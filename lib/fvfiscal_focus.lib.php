<?php

require_once __DIR__ . '/../class/FvBatch.class.php';
require_once __DIR__ . '/../class/FvBatchEvent.class.php';
require_once __DIR__ . '/../class/FvBatchLine.class.php';
require_once __DIR__ . '/../class/FvFocusJob.class.php';
require_once __DIR__ . '/../class/FvJob.class.php';
require_once __DIR__ . '/../class/FvJobLine.class.php';
require_once __DIR__ . '/../class/FvPartnerProfile.class.php';

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
     * Reset error information.
     */
    private function resetErrors()
    {
        $this->error = '';
        $this->errors = array();
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
        } elseif (!empty($this->conf->global->FVFISCAL_MYPARAM1)) {
            $endpoint = $this->conf->global->FVFISCAL_MYPARAM1;
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
