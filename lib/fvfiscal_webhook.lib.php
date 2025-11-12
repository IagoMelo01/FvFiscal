<?php
/*
 * Webhook processing helpers for Focus integration.
 */

require_once __DIR__ . '/../class/FvFocusJob.class.php';
require_once __DIR__ . '/../class/FvNfeOut.class.php';
require_once __DIR__ . '/../class/FvNfeEvent.class.php';
require_once __DIR__ . '/../class/FvNfeIn.class.php';
require_once __DIR__ . '/../class/FvMdfe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

/**
 * Process Focus webhook payloads and update Dolibarr objects.
 */
class FvFiscalWebhookProcessor
{
    /** @var DoliDB */
    private $db;

    /** @var Conf */
    private $conf;

    /** @var User */
    private $user;

    /** @var array<string, int> */
    private $statusMap = array(
        'queued' => 0,
        'pending' => 0,
        'waiting' => 0,
        'scheduled' => 0,
        'processing' => 1,
        'running' => 1,
        'executing' => 1,
        'finished' => 2,
        'completed' => 2,
        'concluded' => 2,
        'success' => 2,
        'authorized' => 2,
        'autorizado' => 2,
        'ok' => 2,
        'failed' => 3,
        'error' => 3,
        'denied' => 3,
        'rejected' => 3,
        'cancelled' => 4,
        'canceled' => 4,
    );

    /**
     * @param DoliDB $db   Database handler
     * @param Conf   $conf Dolibarr configuration
     * @param User   $user Technical user for updates
     */
    public function __construct(DoliDB $db, Conf $conf, User $user)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->user = $user;
    }

    /**
     * Handle webhook payload and persist changes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws RuntimeException When processing fails
     */
    public function process(array $payload)
    {
        $job = $this->resolveJob($payload);
        if (!$job) {
            throw new RuntimeException('Focus job not found', 404);
        }

        $this->db->begin();

        try {
            $this->updateJob($job, $payload);
            $documents = $this->updateRelatedDocuments($job, $payload);
            $this->db->commit();
        } catch (Exception $exception) {
            $this->db->rollback();
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException($exception->getMessage(), 500);
        }

        return array(
            'job_id' => $job->id,
            'job_type' => $job->job_type,
            'documents' => $documents,
        );
    }

    /**
     * Try to locate the Focus job referenced by the payload.
     *
     * @param array<string, mixed> $payload
     * @return FvFocusJob|null
     */
    private function resolveJob(array $payload)
    {
        $jobId = $this->extractInt($payload, array('focus_job_id', 'job_id'));
        if (!$jobId && isset($payload['job']) && is_array($payload['job'])) {
            $jobId = $this->extractInt($payload['job'], array('focus_job_id', 'job_id'));
            if (!$jobId && isset($payload['job']['metadata']) && is_array($payload['job']['metadata'])) {
                $jobId = $this->extractInt($payload['job']['metadata'], array('focus_job_id', 'dolibarr_job_id', 'fv_focus_job_id'));
            }
        }

        $job = new FvFocusJob($this->db);
        if ($jobId) {
            if ($job->fetch($jobId) > 0) {
                return $job;
            }
            return null;
        }

        $jobData = $payload['job'] ?? array();
        if (!is_array($jobData)) {
            $jobData = array();
        }
        $remoteId = $this->extractString($jobData, array('remote_id', 'id', 'uuid'));
        if ($remoteId === '') {
            $remoteId = $this->extractString($payload, array('remote_id', 'id', 'uuid'));
        }
        $jobType = $this->extractString($jobData, array('job_type', 'type'));
        if ($jobType === '') {
            $jobType = $this->extractString($payload, array('job_type', 'type'));
        }

        if ($remoteId === '') {
            return null;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_focus_job";
        $sql .= ' WHERE entity IN (' . getEntity('fv_focus_job') . ')';
        $sql .= " AND remote_id = '" . $this->db->escape($remoteId) . "'";
        if ($jobType !== '') {
            $types = array($jobType);
            if (strpos($jobType, 'job.') !== 0) {
                $types[] = 'job.' . $jobType;
            }
            if (strpos($jobType, 'batch.') !== 0) {
                $types[] = 'batch.' . $jobType;
            }
            $sql .= " AND job_type IN ('" . implode("','", array_map(array($this->db, 'escape'), $types)) . "')";
        }
        $sql .= ' ORDER BY rowid DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror(), 500);
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }

        if ($job->fetch((int) $obj->rowid) > 0) {
            return $job;
        }

        return null;
    }

    /**
     * Update job fields from payload data.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $payload
     * @return void
     */
    private function updateJob(FvFocusJob $job, array $payload)
    {
        $jobData = $payload['job'] ?? array();
        if (!is_array($jobData)) {
            $jobData = array();
        }

        $remoteId = $this->extractString($jobData, array('remote_id', 'id', 'uuid'));
        if ($remoteId === '') {
            $remoteId = $this->extractString($payload, array('remote_id', 'id', 'uuid'));
        }
        if ($remoteId !== '' && $job->remote_id !== $remoteId) {
            $job->remote_id = $remoteId;
        }

        $statusValue = $jobData['status'] ?? ($payload['status'] ?? null);
        $status = $this->mapStatus($statusValue, $job->status);
        $job->status = $status;

        $job->attempt_count = $this->extractInt($jobData, array('attempt_count', 'attempts'), $job->attempt_count);

        $scheduled = $this->parseDatetime($jobData['scheduled_for'] ?? ($payload['scheduled_for'] ?? null));
        if ($scheduled) {
            $job->scheduled_for = $scheduled;
        }
        $started = $this->parseDatetime($jobData['started_at'] ?? ($payload['started_at'] ?? null));
        if ($started) {
            $job->started_at = $started;
        }
        $finished = $this->parseDatetime($jobData['finished_at'] ?? ($payload['finished_at'] ?? null));
        if ($finished) {
            $job->finished_at = $finished;
        }

        $error = $this->extractString($jobData, array('error', 'error_message', 'message', 'motivo'));
        if ($error === '') {
            $error = $this->extractString($payload, array('error', 'error_message', 'message', 'motivo'));
        }
        $job->error_message = $error;

        $job->response_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($job->update($this->user) < 0) {
            throw new RuntimeException($job->error ?: 'FocusJobUpdateFailed', 500);
        }
    }

    /**
     * Update any document linked to the job.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $payload
     * @return array<int, array<string, mixed>>
     */
    private function updateRelatedDocuments(FvFocusJob $job, array $payload)
    {
        $documents = array();
        $candidates = $this->collectDocumentCandidates($payload);
        if (empty($candidates)) {
            return $documents;
        }

        foreach ($candidates as $candidate) {
            $type = $this->resolveDocumentType($job->job_type, $candidate);
            if ($type === '') {
                continue;
            }

            switch ($type) {
                case 'nfe_out':
                    $documents = array_merge($documents, $this->updateNfeOutDocuments($job, $candidate));
                    break;
                case 'nfe_in':
                    $documents = array_merge($documents, $this->updateNfeInDocuments($job, $candidate));
                    break;
                case 'nfe_event':
                    $documents = array_merge($documents, $this->updateNfeEventDocuments($job, $candidate));
                    break;
                case 'mdfe':
                    $documents = array_merge($documents, $this->updateMdfeDocuments($job, $candidate));
                    break;
            }
        }

        return $documents;
    }

    /**
     * Gather potential document payloads.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function collectDocumentCandidates(array $payload)
    {
        $candidates = array();
        $possibleKeys = array('document', 'data', 'result', 'payload', 'nfe', 'mdfe', 'event');

        if (isset($payload['documents']) && is_array($payload['documents'])) {
            foreach ($payload['documents'] as $item) {
                if (is_array($item) && !empty($item)) {
                    $candidates[] = $item;
                }
            }
        }

        foreach ($possibleKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && !empty($payload[$key])) {
                $candidates[] = $payload[$key];
            }
        }

        if (empty($candidates)) {
            $candidate = $payload;
            unset($candidate['job']);
            if (is_array($candidate) && !empty($candidate)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Determine document type based on job type and payload hints.
     *
     * @param string                  $jobType
     * @param array<string, mixed>    $payload
     * @return string
     */
    private function resolveDocumentType($jobType, array $payload)
    {
        $typeHint = $this->extractString($payload, array('document_type', 'doc_type', 'tipo'));
        $typeHint = strtolower($typeHint);
        if (strpos($typeHint, 'mdfe') !== false) {
            return 'mdfe';
        }
        if (strpos($typeHint, 'event') !== false || strpos($typeHint, 'evento') !== false) {
            return 'nfe_event';
        }
        if ($typeHint === 'nfe_in' || strpos($typeHint, 'entrada') !== false) {
            return 'nfe_in';
        }
        if ($typeHint === 'nfe_out' || $typeHint === 'nfe' || strpos($typeHint, 'nfe') !== false) {
            return 'nfe_out';
        }

        if (!empty($payload['mdfe_key']) || !empty($payload['chave_mdfe'])) {
            return 'mdfe';
        }
        if (!empty($payload['event_type']) || !empty($payload['tipo_evento'])) {
            return 'nfe_event';
        }
        if (!empty($payload['nfe_key']) || !empty($payload['chave']) || !empty($payload['chave_nfe'])) {
            return 'nfe_out';
        }
        if (!empty($payload['doc_type']) && strpos(strtolower((string) $payload['doc_type']), 'in') !== false) {
            return 'nfe_in';
        }

        $jobType = strtolower((string) $jobType);
        if (strpos($jobType, 'mdfe') !== false) {
            return 'mdfe';
        }
        if (strpos($jobType, 'event') !== false) {
            return 'nfe_event';
        }
        if (strpos($jobType, 'nfe_in') !== false || strpos($jobType, 'nfe.import') !== false || strpos($jobType, 'nfe.receive') !== false) {
            return 'nfe_in';
        }
        if (strpos($jobType, 'nfe') !== false) {
            return 'nfe_out';
        }

        return '';
    }

    /**
     * Update NF-e outbound documents linked to the job.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $data
     * @return array<int, array<string, mixed>>
     */
    private function updateNfeOutDocuments(FvFocusJob $job, array $data)
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_nfe_out WHERE fk_focus_job = " . ((int) $job->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror(), 500);
        }

        $updated = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $document = new FvNfeOut($this->db);
            if ($document->fetch((int) $obj->rowid, null, false) <= 0) {
                continue;
            }
            $changed = false;
            if (empty($document->fk_focus_job)) {
                $document->fk_focus_job = $job->id;
                $changed = true;
            }
            $changed |= $this->applyCommonDocumentStatus($document, $data);
            $changed |= $this->applyNfeOutData($document, $data);
            $changed |= $this->storeDocumentFiles($document, 'nfe_out', $data, array('xml', 'pdf'));
            if ($changed) {
                if ($document->update($this->user) < 0) {
                    throw new RuntimeException($document->error ?: 'NfeOutUpdateFailed', 500);
                }
            }
            $updated[] = array(
                'id' => $document->id,
                'type' => 'nfe_out',
                'nfe_key' => $document->nfe_key,
                'protocol_number' => $document->protocol_number,
                'xml_path' => $document->xml_path,
                'pdf_path' => $document->pdf_path,
            );
        }
        $this->db->free($resql);

        return $updated;
    }

    /**
     * Update NF-e inbound documents.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $data
     * @return array<int, array<string, mixed>>
     */
    private function updateNfeInDocuments(FvFocusJob $job, array $data)
    {
        $prototype = new FvNfeIn($this->db);
        if (!array_key_exists('fk_focus_job', $prototype->fields)) {
            return array();
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_nfe_in WHERE fk_focus_job = " . ((int) $job->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror(), 500);
        }

        $updated = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $document = new FvNfeIn($this->db);
            if ($document->fetch((int) $obj->rowid, null) <= 0) {
                continue;
            }
            $changed = $this->applyCommonDocumentStatus($document, $data);
            $changed |= $this->applyNfeInData($document, $data);
            $changed |= $this->storeDocumentFiles($document, 'nfe_in', $data, array('xml', 'pdf'));
            if ($changed) {
                if ($document->update($this->user) < 0) {
                    throw new RuntimeException($document->error ?: 'NfeInUpdateFailed', 500);
                }
            }
            $updated[] = array(
                'id' => $document->id,
                'type' => 'nfe_in',
                'nfe_key' => $document->nfe_key,
                'xml_path' => $document->xml_path,
                'pdf_path' => $document->pdf_path,
            );
        }
        $this->db->free($resql);

        return $updated;
    }

    /**
     * Update NF-e event documents.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $data
     * @return array<int, array<string, mixed>>
     */
    private function updateNfeEventDocuments(FvFocusJob $job, array $data)
    {
        $prototype = new FvNfeEvent($this->db);
        if (!array_key_exists('fk_focus_job', $prototype->fields)) {
            return array();
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_nfe_event WHERE fk_focus_job = " . ((int) $job->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror(), 500);
        }

        $updated = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $document = new FvNfeEvent($this->db);
            if ($document->fetch((int) $obj->rowid, null) <= 0) {
                continue;
            }
            $changed = $this->applyCommonDocumentStatus($document, $data);
            $changed |= $this->applyNfeEventData($document, $data);
            $changed |= $this->storeDocumentFiles($document, 'nfe_event', $data, array('xml'));
            if ($changed) {
                if ($document->update($this->user) < 0) {
                    throw new RuntimeException($document->error ?: 'NfeEventUpdateFailed', 500);
                }
            }
            $updated[] = array(
                'id' => $document->id,
                'type' => 'nfe_event',
                'event_type' => $document->event_type,
                'protocol_number' => $document->protocol_number,
                'xml_path' => $document->xml_path,
            );
        }
        $this->db->free($resql);

        return $updated;
    }

    /**
     * Update MDF-e documents.
     *
     * @param FvFocusJob               $job
     * @param array<string, mixed>     $data
     * @return array<int, array<string, mixed>>
     */
    private function updateMdfeDocuments(FvFocusJob $job, array $data)
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_mdfe WHERE fk_focus_job = " . ((int) $job->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException($this->db->lasterror(), 500);
        }

        $updated = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $document = new FvMdfe($this->db);
            if ($document->fetch((int) $obj->rowid, null) <= 0) {
                continue;
            }
            $changed = false;
            if (empty($document->fk_focus_job)) {
                $document->fk_focus_job = $job->id;
                $changed = true;
            }
            $changed |= $this->applyCommonDocumentStatus($document, $data);
            $changed |= $this->applyMdfeData($document, $data);
            $changed |= $this->storeDocumentFiles($document, 'mdfe', $data, array('xml', 'pdf'));
            if ($changed) {
                if ($document->update($this->user) < 0) {
                    throw new RuntimeException($document->error ?: 'MdfeUpdateFailed', 500);
                }
            }
            $updated[] = array(
                'id' => $document->id,
                'type' => 'mdfe',
                'mdfe_key' => $document->mdfe_key,
                'protocol_number' => $document->protocol_number,
                'xml_path' => $document->xml_path,
                'pdf_path' => $document->pdf_path,
            );
        }
        $this->db->free($resql);

        return $updated;
    }

    /**
     * Apply status metadata to a document.
     *
     * @param CommonObject             $document
     * @param array<string, mixed>     $data
     * @return bool True when modified
     */
    private function applyCommonDocumentStatus(CommonObject $document, array $data)
    {
        $changed = false;
        $statusValue = $data['status'] ?? $data['situacao'] ?? null;
        if ($statusValue !== null) {
            $status = $this->mapStatus($statusValue, $document->status);
            if ($status !== $document->status) {
                $document->status = $status;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Update NF-e outbound fields.
     *
     * @param FvNfeOut                 $document
     * @param array<string, mixed>     $data
     * @return bool
     */
    private function applyNfeOutData(FvNfeOut $document, array $data)
    {
        $changed = false;

        $issue = $this->parseDatetime($data['issue_at'] ?? ($data['issued_at'] ?? ($data['data_emissao'] ?? null)));
        if ($issue && (int) $document->issue_at !== (int) $issue) {
            $document->issue_at = $issue;
            $changed = true;
        }

        $fields = array(
            'nfe_key' => array('nfe_key', 'chave', 'chave_nfe'),
            'protocol_number' => array('protocol_number', 'numero_protocolo', 'protocolo'),
            'series' => array('series', 'serie'),
            'document_number' => array('document_number', 'numero', 'numero_nfe'),
            'ref' => array('ref', 'referencia'),
            'operation_type' => array('operation_type', 'tipo_operacao'),
        );
        foreach ($fields as $field => $keys) {
            $value = $this->extractString($data, $keys);
            if ($value !== '' && $document->{$field} !== $value) {
                $document->{$field} = $value;
                $changed = true;
            }
        }

        $amountFields = array(
            'total_products' => array('total_products', 'valor_produtos'),
            'total_discount' => array('total_discount', 'valor_descontos'),
            'total_tax' => array('total_tax', 'valor_impostos', 'total_taxes'),
            'total_amount' => array('total_amount', 'valor_total'),
        );
        foreach ($amountFields as $field => $keys) {
            $value = $this->extractNumeric($data, $keys);
            if ($value !== null && price2num($document->{$field}, 'MT') != price2num($value, 'MT')) {
                $document->{$field} = price2num($value, 'MT');
                $changed = true;
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $document->json_response !== $json) {
            $document->json_response = $json;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Update NF-e inbound fields.
     *
     * @param FvNfeIn                  $document
     * @param array<string, mixed>     $data
     * @return bool
     */
    private function applyNfeInData(FvNfeIn $document, array $data)
    {
        $changed = false;

        $issue = $this->parseDatetime($data['issue_at'] ?? ($data['issued_at'] ?? ($data['data_emissao'] ?? null)));
        if ($issue && (int) $document->issue_at !== (int) $issue) {
            $document->issue_at = $issue;
            $changed = true;
        }
        $arrival = $this->parseDatetime($data['arrival_at'] ?? ($data['data_entrada'] ?? null));
        if ($arrival && (int) $document->arrival_at !== (int) $arrival) {
            $document->arrival_at = $arrival;
            $changed = true;
        }

        $fields = array(
            'nfe_key' => array('nfe_key', 'chave', 'chave_nfe'),
            'ref' => array('ref', 'referencia'),
            'operation_type' => array('operation_type', 'tipo_operacao'),
        );
        foreach ($fields as $field => $keys) {
            $value = $this->extractString($data, $keys);
            if ($value !== '' && $document->{$field} !== $value) {
                $document->{$field} = $value;
                $changed = true;
            }
        }

        $amountFields = array(
            'total_products' => array('total_products', 'valor_produtos'),
            'total_tax' => array('total_tax', 'valor_impostos', 'total_taxes'),
            'total_amount' => array('total_amount', 'valor_total'),
        );
        foreach ($amountFields as $field => $keys) {
            $value = $this->extractNumeric($data, $keys);
            if ($value !== null && price2num($document->{$field}, 'MT') != price2num($value, 'MT')) {
                $document->{$field} = price2num($value, 'MT');
                $changed = true;
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $document->json_payload !== $json) {
            $document->json_payload = $json;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Update NF-e event fields.
     *
     * @param FvNfeEvent               $document
     * @param array<string, mixed>     $data
     * @return bool
     */
    private function applyNfeEventData(FvNfeEvent $document, array $data)
    {
        $changed = false;

        $fields = array(
            'event_type' => array('event_type', 'tipo_evento'),
            'protocol_number' => array('protocol_number', 'numero_protocolo', 'protocolo'),
            'description' => array('description', 'descricao', 'mensagem'),
        );
        foreach ($fields as $field => $keys) {
            $value = $this->extractString($data, $keys);
            if ($value !== '' && $document->{$field} !== $value) {
                $document->{$field} = $value;
                $changed = true;
            }
        }

        $received = $this->parseDatetime($data['received_at'] ?? ($data['data_evento'] ?? null));
        if ($received && (int) $document->received_at !== (int) $received) {
            $document->received_at = $received;
            $changed = true;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $document->json_response !== $json) {
            $document->json_response = $json;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Update MDF-e fields.
     *
     * @param FvMdfe                   $document
     * @param array<string, mixed>     $data
     * @return bool
     */
    private function applyMdfeData(FvMdfe $document, array $data)
    {
        $changed = false;

        $issue = $this->parseDatetime($data['issue_at'] ?? ($data['issued_at'] ?? ($data['data_emissao'] ?? null)));
        if ($issue && (int) $document->issue_at !== (int) $issue) {
            $document->issue_at = $issue;
            $changed = true;
        }

        $fields = array(
            'mdfe_key' => array('mdfe_key', 'chave', 'chave_mdfe'),
            'protocol_number' => array('protocol_number', 'numero_protocolo', 'protocolo'),
            'ref' => array('ref', 'referencia'),
        );
        foreach ($fields as $field => $keys) {
            $value = $this->extractString($data, $keys);
            if ($value !== '' && $document->{$field} !== $value) {
                $document->{$field} = $value;
                $changed = true;
            }
        }

        $amountFields = array(
            'total_ctes' => array('total_ctes', 'quantidade_ctes'),
            'total_weight' => array('total_weight', 'peso_total'),
            'total_value' => array('total_value', 'valor_total'),
        );
        foreach ($amountFields as $field => $keys) {
            $value = $this->extractNumeric($data, $keys);
            if ($value !== null && price2num($document->{$field}, 'MT') != price2num($value, 'MT')) {
                $document->{$field} = price2num($value, 'MT');
                $changed = true;
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $document->json_response !== $json) {
            $document->json_response = $json;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Download XML/PDF files and update object paths.
     *
     * @param CommonObject             $document
     * @param string                   $subdir
     * @param array<string, mixed>     $data
     * @param array<int, string>       $types
     * @return bool
     */
    private function storeDocumentFiles(CommonObject $document, $subdir, array $data, array $types)
    {
        $changed = false;
        $baseRelative = 'fvfiscal/' . $subdir . '/' . ((int) $document->id);
        $basePath = rtrim(DOL_DATA_ROOT, '/') . '/' . $baseRelative;
        if (!dol_is_dir($basePath)) {
            dol_mkdir($basePath);
        }

        foreach ($types as $type) {
            $fileInfo = $this->resolveDocumentFile($data, $type);
            if (!$fileInfo) {
                continue;
            }
            $filename = $fileInfo['name'];
            $absolute = $basePath . '/' . $filename;
            $content = '';
            if (!empty($fileInfo['content'])) {
                $content = $fileInfo['content'];
            } elseif (!empty($fileInfo['url'])) {
                $httpResult = getURLContent($fileInfo['url']);
                if (!empty($httpResult['content'])) {
                    $content = $httpResult['content'];
                }
            }

            if ($content === '') {
                continue;
            }

            dol_file_put_contents($absolute, $content);
            $relative = $baseRelative . '/' . $filename;
            if ($type === 'xml' && $document->xml_path !== $relative) {
                $document->xml_path = $relative;
                $changed = true;
            }
            if ($type === 'pdf' && $document->pdf_path !== $relative) {
                $document->pdf_path = $relative;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Resolve document file metadata.
     *
     * @param array<string, mixed> $data
     * @param string               $type
     * @return array<string, string>|null
     */
    private function resolveDocumentFile(array $data, $type)
    {
        $type = strtolower($type);
        $fileKeys = array();
        $nameKeys = array();
        if ($type === 'xml') {
            $fileKeys = array('xml_url', 'url_xml', 'xml', 'arquivo_xml', 'link_xml', 'download_xml');
            $nameKeys = array('xml_filename', 'nome_xml', 'arquivo_xml_nome');
        } elseif ($type === 'pdf') {
            $fileKeys = array('pdf_url', 'url_pdf', 'url_danfe', 'pdf', 'danfe', 'link_pdf', 'download_pdf', 'url_damdfe');
            $nameKeys = array('pdf_filename', 'nome_pdf', 'arquivo_pdf_nome');
        }

        $file = '';
        foreach ($fileKeys as $key) {
            if (!empty($data[$key])) {
                $file = (string) $data[$key];
                break;
            }
        }
        if ($file === '' && isset($data['downloads'][$type])) {
            $file = (string) $data['downloads'][$type];
        }
        if ($file === '') {
            return null;
        }

        $filename = '';
        foreach ($nameKeys as $key) {
            if (!empty($data[$key])) {
                $filename = dol_sanitizeFileName((string) $data[$key]);
                break;
            }
        }
        if ($filename === '') {
            $filename = $type === 'xml' ? 'document.xml' : 'document.pdf';
        }

        $file = trim($file);
        $content = '';
        $url = '';
        if (preg_match('/^https?:\/\//i', $file)) {
            $url = $file;
        } elseif (strpos($file, '<') === 0 || strpos($file, '<?xml') === 0) {
            $content = $file;
        } else {
            $decoded = base64_decode($file, true);
            if ($decoded !== false && $decoded !== '') {
                $content = $decoded;
            } else {
                $content = $file;
            }
        }

        return array(
            'name' => $filename,
            'url' => $url,
            'content' => $content,
        );
    }

    /**
     * Normalize status values.
     *
     * @param mixed $value
     * @param int   $default
     * @return int
     */
    private function mapStatus($value, $default = 0)
    {
        if ($value === null || $value === '') {
            return (int) $default;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return (int) $default;
        }
        if (isset($this->statusMap[$value])) {
            return $this->statusMap[$value];
        }
        return (int) $default;
    }

    /**
     * Parse datetime into timestamp.
     *
     * @param mixed $value
     * @return int|null
     */
    private function parseDatetime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = dol_stringtotime($value);
        if ($timestamp <= 0) {
            return null;
        }
        return $timestamp;
    }

    /**
     * Extract integer value from payload.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @param int                  $default
     * @return int
     */
    private function extractInt(array $data, array $keys, $default = 0)
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return (int) $data[$key];
            }
        }
        return (int) $default;
    }

    /**
     * Extract string from payload.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @return string
     */
    private function extractString(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                return trim((string) $data[$key]);
            }
        }
        return '';
    }

    /**
     * Extract numeric value.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @return float|null
     */
    private function extractNumeric(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            if (!isset($data[$key])) {
                continue;
            }
            $value = str_replace(array('R$', ' ', ','), array('', '', '.'), (string) $data[$key]);
            if ($value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }
            return (float) $value;
        }

        return null;
    }
}
