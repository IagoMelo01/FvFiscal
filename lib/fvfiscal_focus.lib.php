<?php

require_once __DIR__ . '/../class/FvBatch.class.php';
require_once __DIR__ . '/../class/FvBatchEvent.class.php';
require_once __DIR__ . '/../class/FvBatchLine.class.php';
require_once __DIR__ . '/../class/FvFocusJob.class.php';
require_once __DIR__ . '/../class/FvJob.class.php';
require_once __DIR__ . '/../class/FvJobLine.class.php';
require_once __DIR__ . '/../class/FvPartnerProfile.class.php';
require_once __DIR__ . '/../class/FvNfeOut.class.php';
require_once __DIR__ . '/../class/FvNfeEvent.class.php';
require_once __DIR__ . '/fvfiscal.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

/**
 * Provide helper routines to validate and register Focus operations using Dolibarr objects.
 */
class FvFocusGateway
{
    /** @var DoliDB */
    private $db;

    /** @var Conf */
    private $conf;

    /** @var Translate|null */
    private $langs;

    /** @var string */
    public $error = '';

    /** @var string[] */
    public $errors = array();

    /**
     * @param DoliDB         $db    Database handler
     * @param Conf           $conf  Dolibarr configuration
     * @param Translate|null $langs Translation handler
     */
    public function __construct($db, $conf, $langs = null)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;
    }

    /**
     * Build and persist a new batch with its lines while logging the Focus job.
     *
     * @param User  $user   Active user
     * @param array $batch  Batch fields
     * @param array $lines  Array of line definitions
     * @return FvBatch|int  Instance on success, <0 on error
     */
    public function createBatchWithLines($user, array $batch, array $lines = array())
    {
        $this->resetErrors();

        $endpoint = $this->getFocusEndpoint();
        if (empty($endpoint)) {
            return -1;
        }

        $profileId = (int) ($batch['fk_partner_profile'] ?? 0);
        if ($profileId <= 0) {
            return $this->failWith('PartnerProfileRequired');
        }

        $profile = new FvPartnerProfile($this->db);
        if ($profile->fetch($profileId) <= 0) {
            return $this->failWith('PartnerProfileNotFound');
        }

        $batchType = isset($batch['batch_type']) ? trim((string) $batch['batch_type']) : '';
        if ($batchType === '') {
            return $this->failWith('BatchTypeRequired');
        }

        $this->db->begin();

        $focusJob = $this->createFocusJob($user, 'batch.create', array(
            'endpoint' => $endpoint,
            'batch_type' => $batchType,
            'profile_ref' => $profile->ref,
            'options' => $batch['settings'] ?? $batch['settings_json'] ?? array(),
        ), array(
            'fk_sefaz_profile' => $batch['fk_sefaz_profile'] ?? 0,
            'scheduled_for' => $batch['scheduled_for'] ?? null,
        ));
        if (!$focusJob) {
            $this->db->rollback();
            return -1;
        }

        $batchObject = new FvBatch($this->db);
        $batchObject->entity = $batch['entity'] ?? $this->conf->entity;
        $batchObject->status = $batch['status'] ?? 0;
        $batchObject->ref = $batch['ref'] ?? '';
        $batchObject->fk_partner_profile = $profileId;
        $batchObject->fk_sefaz_profile = $batch['fk_sefaz_profile'] ?? 0;
        $batchObject->fk_focus_job = $focusJob->id;
        $batchObject->batch_type = $batchType;
        $batchObject->scheduled_for = $batch['scheduled_for'] ?? $focusJob->scheduled_for;
        $batchObject->started_at = $batch['started_at'] ?? null;
        $batchObject->finished_at = $batch['finished_at'] ?? null;
        $batchObject->settings_json = $batch['settings_json'] ?? (isset($batch['settings']) ? json_encode($batch['settings']) : null);

        $batchId = $batchObject->create($user);
        if ($batchId <= 0) {
            $this->db->rollback();
            return $this->failWith($batchObject->error ? $batchObject->error : 'BatchCreateFailed', $batchObject->errors);
        }

        foreach ($lines as $lineData) {
            if (empty($lineData['line_type'])) {
                $this->db->rollback();
                return $this->failWith('BatchLineTypeRequired');
            }
            $lineData['status'] = $lineData['status'] ?? 0;
            $result = $batchObject->addLine($user, $lineData);
            if ($result <= 0) {
                $this->db->rollback();
                return $this->failWith('BatchLineCreateFailed');
            }
        }

        $this->db->commit();
        return $batchObject;
    }

    /**
     * Register dispatch of a batch to the external Focus service.
     *
     * @param User    $user    Current user
     * @param FvBatch $batch   Batch instance
     * @param array   $payload Extra payload sent to Focus
     * @return FvFocusJob|int  Focus job instance or <0 on error
     */
    public function sendBatch($user, FvBatch $batch, array $payload = array())
    {
        $this->resetErrors();

        $endpoint = $this->getFocusEndpoint();
        if (empty($endpoint)) {
            return -1;
        }

        if (empty($batch->id)) {
            return $this->failWith('BatchNotPersisted');
        }

        $payload = array_merge(array(
            'endpoint' => $endpoint,
            'batch_id' => $batch->id,
            'batch_type' => $batch->batch_type,
        ), $payload);

        $focusJob = $this->createFocusJob($user, 'batch.dispatch', $payload, array(
            'fk_sefaz_profile' => $payload['fk_sefaz_profile'] ?? 0,
            'scheduled_for' => $payload['scheduled_for'] ?? null,
        ));
        if (!$focusJob) {
            return -1;
        }

        $batch->fk_focus_job = $focusJob->id;
        if (isset($payload['remote_id'])) {
            $batch->remote_id = $payload['remote_id'];
        }
        if (isset($payload['remote_status'])) {
            $batch->remote_status = $payload['remote_status'];
        }
        if (!empty($payload['scheduled_for'])) {
            $batch->scheduled_for = $payload['scheduled_for'];
        }
        if (!empty($payload['status'])) {
            $batch->status = $payload['status'];
        }

        if ($batch->update($user) < 0) {
            return $this->failWith($batch->error ?: 'BatchUpdateFailed', $batch->errors);
        }

        return $focusJob;
    }

    /**
     * Record an event emitted by Focus in Dolibarr objects.
     *
     * @param User    $user    Current user
     * @param FvBatch $batch   Related batch
     * @param string  $type    Event identifier
     * @param array   $payload Payload sent to Focus
     * @param array   $response Response payload
     * @param string  $errorMessage Error message if any
     * @return FvBatchEvent|int Event instance or <0 on error
     */
    public function registerBatchEvent($user, FvBatch $batch, $type, array $payload = array(), array $response = array(), $errorMessage = '')
    {
        $this->resetErrors();

        if (empty($batch->id)) {
            return $this->failWith('BatchNotPersisted');
        }

        $focusJob = $this->createFocusJob($user, 'batch.event.' . $type, array(
            'batch_id' => $batch->id,
            'type' => $type,
            'payload' => $payload,
            'response' => $response,
        ), array(
            'fk_sefaz_profile' => $batch->fk_sefaz_profile ?? 0,
        ));
        if (!$focusJob) {
            return -1;
        }

        $event = new FvBatchEvent($this->db);
        $event->entity = $batch->entity;
        $event->fk_batch = $batch->id;
        $event->fk_focus_job = $focusJob->id;
        $event->event_type = $type;
        $event->event_payload = json_encode($payload);
        $event->response_json = json_encode($response);
        $event->error_message = $errorMessage;

        if ($event->create($user) <= 0) {
            return $this->failWith($event->error ?: 'BatchEventCreateFailed', $event->errors);
        }

        return $event;
    }

    /**
     * Create a job associated with a batch line.
     *
     * @param User        $user   Current user
     * @param FvBatchLine $line   Parent batch line
     * @param array       $data   Job data
     * @return FvJob|int          Job instance or <0 on error
     */
    public function createJobForLine($user, FvBatchLine $line, array $data)
    {
        $this->resetErrors();

        if (empty($line->id) || empty($line->fk_batch)) {
            return $this->failWith('BatchLineNotPersisted');
        }

        $jobType = isset($data['job_type']) ? trim((string) $data['job_type']) : '';
        if ($jobType === '') {
            return $this->failWith('JobTypeRequired');
        }

        $batch = new FvBatch($this->db);
        if ($batch->fetch($line->fk_batch, null, false) <= 0) {
            return $this->failWith('BatchNotFound');
        }

        $endpoint = $this->getFocusEndpoint();
        if (empty($endpoint)) {
            return -1;
        }

        $payload = array_merge(array(
            'endpoint' => $endpoint,
            'batch_id' => $batch->id,
            'batch_line_id' => $line->id,
        ), $data['job_payload'] ?? array());

        $focusJob = $this->createFocusJob($user, 'job.' . $jobType, array(
            'job_type' => $jobType,
            'payload' => $payload,
        ), array(
            'fk_sefaz_profile' => $data['fk_sefaz_profile'] ?? 0,
            'scheduled_for' => $data['scheduled_for'] ?? null,
        ));
        if (!$focusJob) {
            return -1;
        }

        $job = new FvJob($this->db);
        $job->entity = $batch->entity;
        $job->status = $data['status'] ?? 0;
        $job->ref = $data['ref'] ?? '';
        $job->fk_batch = $batch->id;
        $job->fk_batch_line = $line->id;
        $job->fk_focus_job = $focusJob->id;
        $job->job_type = $jobType;
        $job->job_payload = json_encode($payload);
        $job->scheduled_for = $data['scheduled_for'] ?? $focusJob->scheduled_for;
        $job->attempt_count = $data['attempt_count'] ?? 0;
        $job->remote_id = $data['remote_id'] ?? '';
        $job->remote_status = $data['remote_status'] ?? '';

        if ($job->create($user) <= 0) {
            return $this->failWith($job->error ?: 'JobCreateFailed', $job->errors);
        }

        if (!empty($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $lineData) {
                if (empty($lineData['line_type'])) {
                    return $this->failWith('JobLineTypeRequired');
                }
                $lineData['fk_batch_line'] = $line->id;
                $lineData['status'] = $lineData['status'] ?? 0;
                $lineData['payload_json'] = isset($lineData['payload_json']) ? $lineData['payload_json'] : (isset($lineData['payload']) ? json_encode($lineData['payload']) : null);
                $result = $job->addLine($user, $lineData);
                if ($result <= 0) {
                    return $this->failWith('JobLineCreateFailed');
                }
            }
        }

        return $job;
    }

    /**
     * Request NF-e cancellation through Focus endpoint.
     *
     * @param User     $user        Current user
     * @param FvNfeOut $document    NF-e document
     * @param string   $justification Cancellation justification text
     * @return FvNfeEvent|int Event instance on success, <0 on error
     */
    public function cancelOutboundNfe($user, FvNfeOut $document, $justification)
    {
        $this->resetErrors();

        if (empty($document->id)) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusDocumentNotPersisted') : 'NF-e record must be saved before requesting Focus operations';
            return $this->failWith($message);
        }
        if (!$document->canSendCancellation()) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusNotAuthorizedForCancel') : 'Cancellation not allowed for this NF-e';
            return $this->failWith($message);
        }

        $justification = trim((string) $justification);
        if (dol_strlen($justification) < 15) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusJustificationTooShort', 15) : 'Justification must contain at least 15 characters';
            return $this->failWith($message);
        }

        $identifier = $this->resolveNfeIdentifier($document);
        if ($identifier === '') {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusMissingIdentifier') : 'Missing Focus identifier for NF-e';
            return $this->failWith($message);
        }

        $payload = array(
            'justificativa' => $justification,
            'justification' => $justification,
        );
        $response = $this->performFocusRequest('POST', 'nfe/' . rawurlencode($identifier) . '/cancelar', $payload);
        if ($response === null) {
            return -1;
        }

        return $this->persistNfeEvent($user, $document, $payload, $response, 'cancel', FvNfeOut::STATUS_CANCELLED, 'cancelamento');
    }

    /**
     * Send Carta de Correção (CC-e) event to Focus endpoint.
     *
     * @param User     $user     Current user
     * @param FvNfeOut $document NF-e document
     * @param string   $text     Correction text
     * @return FvNfeEvent|int Event instance on success, <0 on error
     */
    public function sendCorrectionLetter($user, FvNfeOut $document, $text)
    {
        $this->resetErrors();

        if (empty($document->id)) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusDocumentNotPersisted') : 'NF-e record must be saved before requesting Focus operations';
            return $this->failWith($message);
        }
        if (!$document->canSendCorrection()) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusNotAuthorizedForCce') : 'Correction letter not allowed for this NF-e';
            return $this->failWith($message);
        }

        $text = trim((string) $text);
        if (dol_strlen($text) < 15) {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusCorrectionTooShort', 15) : 'Correction text must contain at least 15 characters';
            return $this->failWith($message);
        }

        $identifier = $this->resolveNfeIdentifier($document);
        if ($identifier === '') {
            $message = $this->langs ? $this->langs->trans('FvFiscalFocusMissingIdentifier') : 'Missing Focus identifier for NF-e';
            return $this->failWith($message);
        }

        $payload = array(
            'correcao' => $text,
            'correction' => $text,
        );
        $response = $this->performFocusRequest('POST', 'nfe/' . rawurlencode($identifier) . '/cartacorrecao', $payload);
        if ($response === null) {
            return -1;
        }

        return $this->persistNfeEvent($user, $document, $payload, $response, 'cce', FvNfeOut::STATUS_AUTHORIZED, 'cce');
    }

    /**
     * Reset error information.
     */
    private function resetErrors()
    {
        $this->error = '';
        $this->errors = array();
    }

    /**
     * Determine identifier used to address the NF-e in Focus.
     *
     * @param FvNfeOut $document
     * @return string
     */
    private function resolveNfeIdentifier(FvNfeOut $document)
    {
        if (!empty($document->ref)) {
            return (string) $document->ref;
        }
        if (!empty($document->nfe_key)) {
            return (string) $document->nfe_key;
        }

        return '';
    }

    /**
     * Persist NF-e event and update related document.
     *
     * @param User     $user
     * @param FvNfeOut $document
     * @param array    $request
     * @param array    $response
     * @param string   $eventType
     * @param int      $eventStatus
     * @param string   $filenameHint
     * @return FvNfeEvent|int
     */
    private function persistNfeEvent($user, FvNfeOut $document, array $request, array $response, $eventType, $eventStatus, $filenameHint)
    {
        $protocolKeys = array('numero_protocolo', 'protocolo', 'protocol_number', 'protocol');
        $protocol = $this->extractString($response, $protocolKeys);

        $jsonResponse = $this->encodeJson($response);

        $this->db->begin();

        if ($eventType === 'cancel') {
            $document->status = FvNfeOut::STATUS_CANCELLED;
            if ($protocol !== '') {
                $document->protocol_number = $protocol;
            }
            if ($jsonResponse !== null) {
                $document->json_response = $jsonResponse;
            }
            if ($document->update($user) < 0) {
                $this->db->rollback();
                return $this->failWith($document->error ?: 'NfeOutUpdateFailed', $document->errors);
            }
        } else {
            if ($jsonResponse !== null && $jsonResponse !== $document->json_response) {
                $document->json_response = $jsonResponse;
                if ($document->update($user) < 0) {
                    $this->db->rollback();
                    return $this->failWith($document->error ?: 'NfeOutUpdateFailed', $document->errors);
                }
            }
        }

        $event = new FvNfeEvent($this->db);
        $event->entity = $document->entity;
        $event->status = $eventStatus;
        $event->fk_nfeout = $document->id;
        $event->event_type = $eventType;
        $event->event_sequence = (int) ($response['event_sequence'] ?? $response['sequencia_evento'] ?? $response['sequencia'] ?? 1);
        if (!empty($response['data_evento']) || !empty($response['received_at'])) {
            $event->received_at = $this->parseDatetime($response['data_evento'] ?? $response['received_at']);
        } elseif (!empty($response['timestamp'])) {
            $event->received_at = $this->parseDatetime($response['timestamp']);
        }
        if (!empty($event->received_at) && $event->received_at <= 0) {
            $event->received_at = null;
        }
        if (!empty($response['descricao_evento'])) {
            $event->description = (string) $response['descricao_evento'];
        } else {
            $event->description = $this->extractString($response, array('mensagem_sefaz', 'motivo', 'message', 'descricao', 'mensagem'));
        }
        if ($event->description === '' && $eventType === 'cce' && $this->langs) {
            $event->description = $this->langs->trans('FvFiscalFocusCceDescription');
        }
        if ($event->description === '' && $eventType === 'cancel' && $this->langs) {
            $event->description = $this->langs->trans('FvFiscalNfeOutStatusCancelled');
        }
        if ($protocol !== '') {
            $event->protocol_number = $protocol;
        }
        $event->json_payload = $this->encodeJson($request);
        $event->json_response = $jsonResponse;

        $eventId = $event->create($user);
        if ($eventId <= 0) {
            $this->db->rollback();
            return $this->failWith($event->error ?: 'NfeEventCreateFailed', $event->errors);
        }

        $xmlKeys = array('xml', 'xml_cancelamento', 'xml_cancelado', 'arquivo_xml', 'xml_evento');
        if ($eventType === 'cancel') {
            array_unshift($xmlKeys, 'xml_cancelamento');
        }
        $xmlContent = $this->resolveXmlContent($response, $xmlKeys);
        if ($xmlContent !== '') {
            $fileSuffix = $protocol !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $protocol) : '';
            $filename = $filenameHint;
            if ($fileSuffix !== '') {
                $filename .= '-' . strtolower($fileSuffix);
            }
            $filename .= '.xml';
            $relative = $this->storeDocumentContent('nfe_event', $eventId, $xmlContent, $filename);
            if ($relative !== '') {
                $event->xml_path = $relative;
                if ($event->update($user) < 0) {
                    $this->db->rollback();
                    return $this->failWith($event->error ?: 'NfeEventUpdateFailed', $event->errors);
                }
            }
        }

        $this->db->commit();

        return $event;
    }

    /**
     * Perform HTTP request against Focus API.
     *
     * @param string $method
     * @param string $path
     * @param array  $payload
     * @return array<string, mixed>|null
     */
    private function performFocusRequest($method, $path, array $payload = array())
    {
        $endpoint = $this->getFocusEndpoint();
        if (empty($endpoint)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            $this->failWith('Curl extension is required to contact Focus API');
            return null;
        }

        $url = rtrim($endpoint, '/') . '/' . ltrim($path, '/');
        $headers = array('Accept: application/json');
        $token = $this->getFocusToken();
        if ($token !== '') {
            if (stripos($token, 'bearer ') === 0 || stripos($token, 'basic ') === 0) {
                $headers[] = 'Authorization: ' . $token;
            } else {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }

        $body = '';
        if (!empty($payload)) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                $this->failWith('Unable to encode request payload to JSON');
                return null;
            }
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init();
        $method = strtoupper((string) $method);
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        );
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $options);
        $content = curl_exec($curl);
        if ($content === false) {
            $error = curl_error($curl);
            $code = curl_errno($curl);
            curl_close($curl);
            $this->failWith('cURL error ' . $code . ': ' . $error);
            return null;
        }
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode >= 400) {
            $this->handleFocusErrorResponse($content, $httpCode);
            return null;
        }

        if ($content === '' || $content === null) {
            return array();
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->failWith('Invalid JSON from Focus: ' . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    /**
     * Handle HTTP error response from Focus service.
     *
     * @param string $content
     * @param int    $httpCode
     * @return int
     */
    private function handleFocusErrorResponse($content, $httpCode)
    {
        $details = array('HTTP ' . $httpCode);
        $message = 'HTTP ' . $httpCode;
        $content = (string) $content;
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $primary = $this->extractString($decoded, array('message', 'mensagem', 'error', 'motivo'));
                if ($primary !== '') {
                    $message .= ' - ' . $primary;
                    $details[] = $primary;
                }
                if (!empty($decoded['erros']) && is_array($decoded['erros'])) {
                    foreach ($decoded['erros'] as $error) {
                        if (is_array($error)) {
                            $details[] = implode(' - ', array_filter(array_map('strval', $error)));
                        } elseif ($error !== '') {
                            $details[] = (string) $error;
                        }
                    }
                }
            } else {
                $details[] = $content;
            }
        }

        $this->failWith($message, $details);

        return -1;
    }

    /**
     * Resolve XML content from Focus response.
     *
     * @param array<int|string, mixed> $response
     * @param array<int, string>       $keys
     * @return string
     */
    private function resolveXmlContent(array $response, array $keys)
    {
        foreach ($keys as $key) {
            if (!empty($response[$key])) {
                $content = $this->decodeXmlValue($response[$key]);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        if (!empty($response['downloads']) && is_array($response['downloads'])) {
            foreach ($keys as $key) {
                if (!empty($response['downloads'][$key])) {
                    $content = $this->decodeXmlValue($response['downloads'][$key]);
                    if ($content !== '') {
                        return $content;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Decode XML value that may be a URL or base64.
     *
     * @param mixed $value
     * @return string
     */
    private function decodeXmlValue($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $value)) {
            $result = getURLContent($value);
            if (!empty($result['content'])) {
                return (string) $result['content'];
            }
            return '';
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false && trim($decoded) !== '') {
            return $decoded;
        }

        return $value;
    }

    /**
     * Store XML payload under Dolibarr document root.
     *
     * @param string $subdir
     * @param int    $id
     * @param string $content
     * @param string $filename
     * @return string Relative path when stored
     */
    private function storeDocumentContent($subdir, $id, $content, $filename)
    {
        $content = (string) $content;
        if ($content === '') {
            return '';
        }
        $relativeBase = 'fvfiscal/' . $subdir . '/' . ((int) $id);
        $absoluteBase = rtrim(DOL_DATA_ROOT, '/') . '/' . $relativeBase;
        if (!dol_is_dir($absoluteBase)) {
            dol_mkdir($absoluteBase);
        }
        $safeName = dol_sanitizeFileName($filename ?: 'document.xml');
        if ($safeName === '') {
            $safeName = 'document.xml';
        }
        $absolute = $absoluteBase . '/' . $safeName;
        dol_file_put_contents($absolute, $content);

        return $relativeBase . '/' . $safeName;
    }

    /**
     * Extract first non-empty string from response array.
     *
     * @param array $data
     * @param array $keys
     * @return string
     */
    private function extractString(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    /**
     * Encode array as JSON string.
     *
     * @param array|null $data
     * @return string|null
     */
    private function encodeJson($data)
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        return $json;
    }

    /**
     * Parse date/time coming from Focus payload.
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
        if ($timestamp > 0) {
            return $timestamp;
        }

        return null;
    }

    /**
     * Retrieve Focus API token from configuration.
     *
     * @return string
     */
    private function getFocusToken()
    {
        $token = '';
        if (!empty($this->conf->global->FVFISCAL_FOCUS_TOKEN)) {
            $token = fvfiscal_decrypt_value($this->conf->global->FVFISCAL_FOCUS_TOKEN);
        } elseif (($env = getenv('FV_FISCAL_FOCUS_TOKEN')) !== false) {
            $token = (string) $env;
        }

        return trim((string) $token);
    }

    /**
     * Retrieve the Focus endpoint from Dolibarr configuration.
     *
     * @return string
     */
    private function getFocusEndpoint()
    {
        $endpoint = '';
        if (!empty($this->conf->global->FVFISCAL_FOCUS_ENDPOINT)) {
            $endpoint = $this->conf->global->FVFISCAL_FOCUS_ENDPOINT;
        }

        if (empty($endpoint)) {
            $this->failWith('FocusEndpointNotConfigured');
        }

        return $endpoint;
    }

    /**
     * Create a Focus job row for audit.
     *
     * @param User  $user    Current user
     * @param string $type   Job type
     * @param array  $payload Payload data
     * @param array  $options Optional fields (fk_sefaz_profile, status, scheduled_for)
     * @return FvFocusJob|null
     */
    private function createFocusJob($user, $type, array $payload, array $options = array())
    {
        $focusJob = new FvFocusJob($this->db);
        $focusJob->entity = $this->conf->entity;
        $focusJob->status = $options['status'] ?? 0;
        if (!empty($options['fk_sefaz_profile'])) {
            $focusJob->fk_sefaz_profile = $options['fk_sefaz_profile'];
        }
        $focusJob->job_type = $type;
        $focusJob->payload_json = json_encode($payload);
        if (!empty($options['scheduled_for'])) {
            $focusJob->scheduled_for = $options['scheduled_for'];
        }

        if ($focusJob->create($user) <= 0) {
            $this->failWith($focusJob->error ?: 'FocusJobCreateFailed', $focusJob->errors);
            return null;
        }

        return $focusJob;
    }

    /**
     * Helper to register an error message.
     *
     * @param string   $message Error message key
     * @param string[] $extra   Additional errors
     * @return int
     */
    private function failWith($message, array $extra = array())
    {
        $this->error = $message;
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
        foreach ($extra as $item) {
            if (!in_array($item, $this->errors, true)) {
                $this->errors[] = $item;
            }
        }

        return -1;
    }
}
