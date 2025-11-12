<?php
/*
 * Focus webhook endpoint for Dolibarr FvFiscal module.
 */

if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', 1);
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
    $res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
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
    http_response_code(500);
    echo json_encode(array('error' => 'Dolibarr bootstrap failed'));
    exit;
}

require_once __DIR__ . '/lib/fvfiscal_webhook.lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (empty($conf->fvfiscal->enabled)) {
    http_response_code(503);
    echo json_encode(array('error' => 'FvFiscal module disabled'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(array('error' => 'Method not allowed'));
    exit;
}

$expectedSecret = '';
if (function_exists('getDolGlobalString')) {
    $expectedSecret = (string) getDolGlobalString('FV_FISCAL_WEBHOOK_SECRET', '');
} elseif (!empty($conf->global->FV_FISCAL_WEBHOOK_SECRET)) {
    $expectedSecret = (string) $conf->global->FV_FISCAL_WEBHOOK_SECRET;
}

if ($expectedSecret === '') {
    http_response_code(503);
    echo json_encode(array('error' => 'Webhook secret not configured'));
    exit;
}

$receivedSecret = isset($_SERVER['HTTP_X_WEBHOOK_SECRET']) ? trim((string) $_SERVER['HTTP_X_WEBHOOK_SECRET']) : '';
if ($receivedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(401);
    echo json_encode(array('error' => 'Invalid webhook secret'));
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}
$rawBody = trim($rawBody);
if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'Empty request body'));
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid JSON payload'));
    exit;
}

$webhookUser = new User($db);
$webhookUser->id = 0;
$webhookUser->entity = $conf->entity;
$webhookUser->login = 'focuswebhook';
$webhookUser->lastname = 'Focus';
$webhookUser->firstname = 'Webhook';
$webhookUser->admin = 1;
$webhookUser->rights = array();

$processor = new FvFiscalWebhookProcessor($db, $conf, $webhookUser);

try {
    $result = $processor->process($payload);
    http_response_code(200);
    echo json_encode(array('status' => 'ok', 'result' => $result));
    exit;
} catch (RuntimeException $exception) {
    $code = (int) $exception->getCode();
    if ($code < 100 || $code >= 600) {
        $code = 500;
    }
    http_response_code($code);
    echo json_encode(array('error' => $exception->getMessage()));
    exit;
} catch (Exception $exception) {
    http_response_code(500);
    echo json_encode(array('error' => $exception->getMessage()));
    exit;
}
