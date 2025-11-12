<?php
/* Copyright (C) 2025           SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Gateway for Focus batch operations triggered from Dolibarr UI.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
    $res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
    $res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once __DIR__.'/lib/fvfiscal_focus.lib.php';
require_once __DIR__.'/lib/fvfiscal_helpers.php';
require_once __DIR__.'/lib/fvfiscal_permissions.php';
require_once __DIR__.'/class/FvBatch.class.php';
require_once __DIR__.'/class/FvBatchEvent.class.php';
require_once __DIR__.'/class/FvBatchLine.class.php';
require_once __DIR__.'/class/FvJob.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('fvfiscal@fvfiscal'));

if (empty($conf->fvfiscal->enabled)) {
    accessforbidden();
}
if (!$user->hasRight(
    FvFiscalPermissions::MODULE,
    FvFiscalPermissions::BATCH,
    FvFiscalPermissions::BATCH_WRITE
)) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$token = GETPOST('token', 'alphanohtml');
$backtopage = GETPOST('backtopage', 'sanitizedurl');
if (empty($backtopage)) {
    $backtopage = dol_buildpath('/fvfiscal/batch_overview.php', 1);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: '.$backtopage);
    exit;
}

if (!fvfiscal_checkToken($token)) {
    setEventMessages($langs->trans('FvFiscalInvalidToken'), null, 'errors');
    header('Location: '.$backtopage);
    exit;
}

if (empty($action)) {
    setEventMessages($langs->trans('FvFiscalFocusInvalidAction'), null, 'errors');
    header('Location: '.$backtopage);
    exit;
}

$gateway = new FvFocusGateway($db, $conf, $langs);
$result = null;

switch ($action) {
    case 'create_batch':
        $result = fvfiscal_handle_create_batch($gateway, $langs, $user);
        break;
    case 'send_batch':
        $result = fvfiscal_handle_send_batch($gateway, $langs, $user);
        break;
    case 'register_event':
        $result = fvfiscal_handle_register_event($gateway, $langs, $user);
        break;
    case 'create_job':
        $result = fvfiscal_handle_create_job($gateway, $langs, $user);
        break;
    default:
        setEventMessages($langs->trans('FvFiscalFocusInvalidAction'), null, 'errors');
        header('Location: '.$backtopage);
        exit;
}

if ($result === true) {
    header('Location: '.$backtopage);
    exit;
}

if ($result === false) {
    fvfiscal_register_gateway_error($gateway, $langs);
    header('Location: '.$backtopage);
    exit;
}

if (is_string($result)) {
    setEventMessages($result, null, 'errors');
    header('Location: '.$backtopage);
    exit;
}

header('Location: '.$backtopage);
exit;

/**
 * Handle batch creation request.
 *
 * @param FvFocusGateway $gateway Focus gateway
 * @param Translate      $langs   Translation handler
 * @param User           $user    Current user
 * @return bool|string   True on success, false if service error, string with error message otherwise
 */
function fvfiscal_handle_create_batch(FvFocusGateway $gateway, Translate $langs, User $user)
{
    $partnerProfileId = GETPOSTINT('fk_partner_profile');
    if ($partnerProfileId <= 0) {
        return $langs->trans('FvFiscalFocusMissingParameter', 'fk_partner_profile');
    }

    $batchType = trim((string) GETPOST('batch_type', 'alphanohtml'));
    if ($batchType === '') {
        return $langs->trans('FvFiscalFocusMissingParameter', 'batch_type');
    }

    $batchData = array(
        'entity' => $user->entity,
        'fk_partner_profile' => $partnerProfileId,
        'batch_type' => $batchType,
    );

    $status = GETPOSTINT('status');
    if ($status >= 0) {
        $batchData['status'] = $status;
    }

    $batchData['ref'] = trim((string) GETPOST('ref', 'alphanohtml'));

    $sefazProfile = GETPOSTINT('fk_sefaz_profile');
    if ($sefazProfile > 0) {
        $batchData['fk_sefaz_profile'] = $sefazProfile;
    }

    $remoteId = trim((string) GETPOST('remote_id', 'alphanohtml'));
    if ($remoteId !== '') {
        $batchData['remote_id'] = $remoteId;
    }

    $remoteStatus = trim((string) GETPOST('remote_status', 'alphanohtml'));
    if ($remoteStatus !== '') {
        $batchData['remote_status'] = $remoteStatus;
    }

    $scheduledFor = fvfiscal_parse_datetime(GETPOST('scheduled_for', 'alphanohtml'));
    if (!empty($scheduledFor)) {
        $batchData['scheduled_for'] = $scheduledFor;
    }

    $startedAt = fvfiscal_parse_datetime(GETPOST('started_at', 'alphanohtml'));
    if (!empty($startedAt)) {
        $batchData['started_at'] = $startedAt;
    }

    $finishedAt = fvfiscal_parse_datetime(GETPOST('finished_at', 'alphanohtml'));
    if (!empty($finishedAt)) {
        $batchData['finished_at'] = $finishedAt;
    }

    $settingsJson = GETPOST('settings_json', 'none');
    if ($settingsJson !== null && $settingsJson !== '') {
        $batchData['settings_json'] = $settingsJson;
    }

    $linesInput = GETPOST('lines', 'array');
    $lines = array();
    if (is_array($linesInput)) {
        foreach ($linesInput as $lineData) {
            if (!is_array($lineData)) {
                continue;
            }
            $sanitized = fvfiscal_sanitize_line($lineData);
            if (!empty($sanitized)) {
                $lines[] = $sanitized;
            }
        }
    } else {
        $linesJson = GETPOST('lines_json', 'none');
        if (!empty($linesJson)) {
            $decoded = json_decode($linesJson, true);
            if (!is_array($decoded)) {
                return $langs->trans('FvFiscalFocusInvalidPayload');
            }
            foreach ($decoded as $lineData) {
                if (!is_array($lineData)) {
                    continue;
                }
                $sanitized = fvfiscal_sanitize_line($lineData);
                if (!empty($sanitized)) {
                    $lines[] = $sanitized;
                }
            }
        }
    }

    $result = $gateway->createBatchWithLines($user, $batchData, $lines);
    if ($result instanceof FvBatch) {
        setEventMessages($langs->trans('FvFiscalFocusBatchCreated'), null, 'mesgs');
        return true;
    }

    return false;
}

/**
 * Handle batch dispatch request.
 *
 * @param FvFocusGateway $gateway Focus gateway
 * @param Translate      $langs   Translation handler
 * @param User           $user    Current user
 * @return bool|string   True on success, false if service error, string with error message otherwise
 */
function fvfiscal_handle_send_batch(FvFocusGateway $gateway, Translate $langs, User $user)
{
    global $db;
    $batchId = GETPOSTINT('batch_id');
    if ($batchId <= 0) {
        return $langs->trans('FvFiscalFocusMissingParameter', 'batch_id');
    }

    $batch = new FvBatch($db);
    if ($batch->fetch($batchId) <= 0) {
        return $langs->trans('FvFiscalFocusBatchNotFound');
    }

    $payload = array();
    $sefazProfile = GETPOSTINT('fk_sefaz_profile');
    if ($sefazProfile > 0) {
        $payload['fk_sefaz_profile'] = $sefazProfile;
    }

    $remoteId = trim((string) GETPOST('remote_id', 'alphanohtml'));
    if ($remoteId !== '') {
        $payload['remote_id'] = $remoteId;
    }

    $remoteStatus = trim((string) GETPOST('remote_status', 'alphanohtml'));
    if ($remoteStatus !== '') {
        $payload['remote_status'] = $remoteStatus;
    }

    $status = GETPOSTINT('status');
    if ($status >= 0) {
        $payload['status'] = $status;
    }

    $scheduledFor = fvfiscal_parse_datetime(GETPOST('scheduled_for', 'alphanohtml'));
    if (!empty($scheduledFor)) {
        $payload['scheduled_for'] = $scheduledFor;
    }

    $payloadJson = GETPOST('payload_json', 'none');
    if (!empty($payloadJson)) {
        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return $langs->trans('FvFiscalFocusInvalidPayload');
        }
        $payload = array_merge($payload, $decoded);
    }

    $result = $gateway->sendBatch($user, $batch, $payload);
    if ($result instanceof FvFocusJob) {
        setEventMessages($langs->trans('FvFiscalFocusBatchDispatched'), null, 'mesgs');
        return true;
    }

    return false;
}

/**
 * Handle event registration request.
 *
 * @param FvFocusGateway $gateway Focus gateway
 * @param Translate      $langs   Translation handler
 * @param User           $user    Current user
 * @return bool|string   True on success, false if service error, string with error message otherwise
 */
function fvfiscal_handle_register_event(FvFocusGateway $gateway, Translate $langs, User $user)
{
    global $db;
    $batchId = GETPOSTINT('batch_id');
    if ($batchId <= 0) {
        return $langs->trans('FvFiscalFocusMissingParameter', 'batch_id');
    }

    $batch = new FvBatch($db);
    if ($batch->fetch($batchId) <= 0) {
        return $langs->trans('FvFiscalFocusBatchNotFound');
    }

    $eventType = trim((string) GETPOST('event_type', 'alphanohtml'));
    if ($eventType === '') {
        return $langs->trans('FvFiscalFocusMissingParameter', 'event_type');
    }

    $payload = fvfiscal_decode_json_array(GETPOST('payload_json', 'none'));
    if ($payload === null) {
        return $langs->trans('FvFiscalFocusInvalidPayload');
    }

    $response = fvfiscal_decode_json_array(GETPOST('response_json', 'none'));
    if ($response === null) {
        return $langs->trans('FvFiscalFocusInvalidPayload');
    }

    $errorMessage = trim((string) GETPOST('error_message', 'restricthtml'));

    $result = $gateway->registerBatchEvent($user, $batch, $eventType, $payload, $response, $errorMessage);
    if ($result instanceof FvBatchEvent) {
        setEventMessages($langs->trans('FvFiscalFocusEventRegistered'), null, 'mesgs');
        return true;
    }

    return false;
}

/**
 * Handle job creation request.
 *
 * @param FvFocusGateway $gateway Focus gateway
 * @param Translate      $langs   Translation handler
 * @param User           $user    Current user
 * @return bool|string   True on success, false if service error, string with error message otherwise
 */
function fvfiscal_handle_create_job(FvFocusGateway $gateway, Translate $langs, User $user)
{
    global $db;
    $lineId = GETPOSTINT('batch_line_id');
    if ($lineId <= 0) {
        return $langs->trans('FvFiscalFocusMissingParameter', 'batch_line_id');
    }

    $line = new FvBatchLine($db);
    if ($line->fetch($lineId) <= 0) {
        return $langs->trans('FvFiscalFocusLineNotFound');
    }

    $jobType = trim((string) GETPOST('job_type', 'alphanohtml'));
    if ($jobType === '') {
        return $langs->trans('FvFiscalFocusMissingParameter', 'job_type');
    }

    $jobData = array(
        'job_type' => $jobType,
    );

    $status = GETPOSTINT('status');
    if ($status >= 0) {
        $jobData['status'] = $status;
    }

    $ref = trim((string) GETPOST('ref', 'alphanohtml'));
    if ($ref !== '') {
        $jobData['ref'] = $ref;
    }

    $scheduledFor = fvfiscal_parse_datetime(GETPOST('scheduled_for', 'alphanohtml'));
    if (!empty($scheduledFor)) {
        $jobData['scheduled_for'] = $scheduledFor;
    }

    $remoteId = trim((string) GETPOST('remote_id', 'alphanohtml'));
    if ($remoteId !== '') {
        $jobData['remote_id'] = $remoteId;
    }

    $remoteStatus = trim((string) GETPOST('remote_status', 'alphanohtml'));
    if ($remoteStatus !== '') {
        $jobData['remote_status'] = $remoteStatus;
    }

    $attemptCount = GETPOSTINT('attempt_count');
    if ($attemptCount > 0) {
        $jobData['attempt_count'] = $attemptCount;
    }

    $sefazProfile = GETPOSTINT('fk_sefaz_profile');
    if ($sefazProfile > 0) {
        $jobData['fk_sefaz_profile'] = $sefazProfile;
    }

    $payload = fvfiscal_decode_json_array(GETPOST('job_payload_json', 'none'));
    if ($payload === null) {
        return $langs->trans('FvFiscalFocusInvalidPayload');
    }
    if (!empty($payload)) {
        $jobData['job_payload'] = $payload;
    }

    $lines = GETPOST('job_lines', 'array');
    $lineList = array();
    if (is_array($lines)) {
        foreach ($lines as $lineData) {
            if (!is_array($lineData)) {
                continue;
            }
            $sanitized = fvfiscal_sanitize_job_line($lineData);
            if (!empty($sanitized)) {
                $lineList[] = $sanitized;
            }
        }
    } else {
        $linesJson = GETPOST('job_lines_json', 'none');
        if (!empty($linesJson)) {
            $decoded = json_decode($linesJson, true);
            if (!is_array($decoded)) {
                return $langs->trans('FvFiscalFocusInvalidPayload');
            }
            foreach ($decoded as $lineData) {
                if (!is_array($lineData)) {
                    continue;
                }
                $sanitized = fvfiscal_sanitize_job_line($lineData);
                if (!empty($sanitized)) {
                    $lineList[] = $sanitized;
                }
            }
        }
    }

    if (!empty($lineList)) {
        $jobData['lines'] = $lineList;
    }

    $result = $gateway->createJobForLine($user, $line, $jobData);
    if ($result instanceof FvJob) {
        setEventMessages($langs->trans('FvFiscalFocusJobCreated'), null, 'mesgs');
        return true;
    }

    return false;
}

/**
 * Register the errors returned by the gateway.
 *
 * @param FvFocusGateway $gateway Gateway instance
 * @param Translate      $langs   Translation handler
 * @return void
 */
function fvfiscal_register_gateway_error(FvFocusGateway $gateway, Translate $langs)
{
    $errorLabel = $gateway->error ? $langs->trans($gateway->error) : $langs->trans('Error');
    $details = array();
    foreach ($gateway->errors as $error) {
        $details[] = $langs->trans($error);
    }
    setEventMessages($langs->trans('FvFiscalFocusActionError', $errorLabel), $details, 'errors');
}

/**
 * Parse a string datetime value to Dolibarr timestamp.
 *
 * @param mixed $value Raw value
 * @return int|null
 */
function fvfiscal_parse_datetime($value)
{
    if (empty($value)) {
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
 * Sanitize batch line payload.
 *
 * @param array $data Raw data
 * @return array
 */
function fvfiscal_sanitize_line(array $data)
{
    $line = array();

    if (!empty($data['line_type'])) {
        $line['line_type'] = trim((string) $data['line_type']);
    }

    if (empty($line['line_type'])) {
        return array();
    }

    if (isset($data['status'])) {
        $line['status'] = (int) $data['status'];
    }

    if (isset($data['fk_origin'])) {
        $line['fk_origin'] = (int) $data['fk_origin'];
    }

    if (!empty($data['fk_origin_type'])) {
        $line['fk_origin_type'] = trim((string) $data['fk_origin_type']);
    }

    if (isset($data['order_position'])) {
        $line['order_position'] = (int) $data['order_position'];
    }

    $scheduledFor = fvfiscal_parse_datetime($data['scheduled_for'] ?? null);
    if (!empty($scheduledFor)) {
        $line['scheduled_for'] = $scheduledFor;
    }

    $startedAt = fvfiscal_parse_datetime($data['started_at'] ?? null);
    if (!empty($startedAt)) {
        $line['started_at'] = $startedAt;
    }

    $finishedAt = fvfiscal_parse_datetime($data['finished_at'] ?? null);
    if (!empty($finishedAt)) {
        $line['finished_at'] = $finishedAt;
    }

    if (!empty($data['payload_json'])) {
        $decoded = json_decode((string) $data['payload_json'], true);
        if (is_array($decoded)) {
            $line['payload_json'] = json_encode($decoded);
        }
    }

    return $line;
}

/**
 * Sanitize job line payload.
 *
 * @param array $data Raw data
 * @return array
 */
function fvfiscal_sanitize_job_line(array $data)
{
    $line = array();

    if (!empty($data['line_type'])) {
        $line['line_type'] = trim((string) $data['line_type']);
    }

    if (empty($line['line_type'])) {
        return array();
    }

    if (isset($data['status'])) {
        $line['status'] = (int) $data['status'];
    }

    if (isset($data['fk_origin'])) {
        $line['fk_origin'] = (int) $data['fk_origin'];
    }

    if (!empty($data['fk_origin_type'])) {
        $line['fk_origin_type'] = trim((string) $data['fk_origin_type']);
    }

    if (isset($data['payload_json'])) {
        $decoded = json_decode((string) $data['payload_json'], true);
        if (is_array($decoded)) {
            $line['payload_json'] = json_encode($decoded);
        }
    }

    return $line;
}

/**
 * Decode an optional JSON parameter into an array.
 *
 * @param string|null $value Raw value
 * @return array|null Returns null if invalid
 */
function fvfiscal_decode_json_array($value)
{
    if ($value === null || $value === '') {
        return array();
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}
