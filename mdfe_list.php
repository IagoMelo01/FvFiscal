<?php
/* Copyright (C) 2025           SuperAdmin */

// Load Dolibarr environment
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
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once __DIR__ . '/class/FvMdfe.class.php';
require_once __DIR__ . '/lib/fvfiscal_permissions.php';
require_once __DIR__ . '/lib/fvfiscal_mdfe_focus_service.class.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('fvfiscal@fvfiscal', 'companies', 'other'));

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

if (!empty($user->socid)) {
    accessforbidden();
}
if (empty($conf->fvfiscal->enabled)) {
    accessforbidden();
}
if (!$user->hasRight(
    FvFiscalPermissions::MODULE,
    FvFiscalPermissions::BATCH,
    FvFiscalPermissions::BATCH_READ
)) {
    accessforbidden();
}

$form = new Form($db);

$limit = GETPOSTINT('limit');
if ($limit <= 0) {
    $limit = empty($conf->liste_limit) ? 25 : (int) $conf->liste_limit;
}
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$offset = $page * $limit;

$dateStartInput = GETPOST('date_start', 'alphanohtml');
$dateEndInput = GETPOST('date_end', 'alphanohtml');
$statusFilter = GETPOST('status', 'alphanohtml');
$destinationFilter = dol_strtoupper(trim((string) GETPOST('destination_state', 'alpha')));

$dateStart = null;
if ($dateStartInput !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStartInput, $matches)) {
    $dateStart = dol_mktime(0, 0, 0, (int) $matches[2], (int) $matches[3], (int) $matches[1]);
}
$dateEnd = null;
if ($dateEndInput !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateEndInput, $matches)) {
    $dateEnd = dol_mktime(23, 59, 59, (int) $matches[2], (int) $matches[3], (int) $matches[1]);
}
$statusValue = ($statusFilter !== '') ? (int) $statusFilter : null;

$confirmCancelId = ($action === 'cancel') ? $id : 0;
$confirmCloseId = ($action === 'close') ? $id : 0;

if ($action === 'do_cancel' && $id > 0) {
    $token = GETPOST('token', 'alpha');
    if (!dol_verify_token($token)) {
        accessforbidden();
    }

    $justification = GETPOST('justification', 'restricthtml');
    $mdfe = new FvMdfe($db);
    if ($mdfe->fetch($id) > 0) {
        $service = new FvMdfeFocusService($db, $conf, $langs);
        $result = $service->cancelManifest($mdfe, $user, $justification);
        if ($result instanceof FvMdfe) {
            setEventMessages($langs->trans('FvFiscalMdfeFocusCancelSuccess', $mdfe->protocol_number), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('FvFiscalMdfeFocusError', $service->error), $service->errors, 'errors');
        }
    } else {
        setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
    }
    header('Location: ' . dol_buildpath('/fvfiscal/mdfe_list.php', 1));
    exit;
}

if ($action === 'do_close' && $id > 0) {
    $token = GETPOST('token', 'alpha');
    if (!dol_verify_token($token)) {
        accessforbidden();
    }

    $payload = array(
        'data_encerramento' => trim((string) GETPOST('closure_date', 'alphanohtml')),
        'municipio' => trim((string) GETPOST('closure_city', 'alphanohtml')),
        'uf' => dol_strtoupper(trim((string) GETPOST('closure_state', 'alpha'))),
    );
    $mdfe = new FvMdfe($db);
    if ($mdfe->fetch($id) > 0) {
        $service = new FvMdfeFocusService($db, $conf, $langs);
        $result = $service->closeManifest($mdfe, $user, $payload);
        if ($result instanceof FvMdfe) {
            setEventMessages($langs->trans('FvFiscalMdfeFocusCloseSuccess', $mdfe->protocol_number), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('FvFiscalMdfeFocusError', $service->error), $service->errors, 'errors');
        }
    } else {
        setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
    }
    header('Location: ' . dol_buildpath('/fvfiscal/mdfe_list.php', 1));
    exit;
}

$sql = 'SELECT md.rowid, md.ref, md.status, md.issue_at, md.destination_city, md.destination_state,'
    . ' md.total_value, md.total_weight, md.mdfe_key, md.protocol_number, md.xml_path, md.pdf_path, md.closure_city, md.closure_state'
    . ' FROM ' . MAIN_DB_PREFIX . 'fv_mdfe as md'
    . ' WHERE md.entity IN (' . getEntity('fv_mdfe') . ')';
if ($statusValue !== null) {
    $sql .= ' AND md.status = ' . $statusValue;
}
if ($dateStart !== null) {
    $sql .= " AND md.issue_at >= '" . $db->idate($dateStart) . "'";
}
if ($dateEnd !== null) {
    $sql .= " AND md.issue_at <= '" . $db->idate($dateEnd) . "'";
}
if ($destinationFilter !== '') {
    $sql .= " AND UPPER(md.destination_state) = '" . $db->escape($destinationFilter) . "'";
}

$sqlCount = 'SELECT COUNT(*) as total FROM (' . $sql . ') as mdcount';
$resCount = $db->query($sqlCount);
$total = 0;
if ($resCount) {
    $obj = $db->fetch_object($resCount);
    if ($obj) {
        $total = (int) $obj->total;
    }
    $db->free($resCount);
}

$sql .= ' ORDER BY md.issue_at DESC';
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
$records = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $records[] = $obj;
    }
    $db->free($resql);
}

$statuses = FvMdfe::getStatusLabels($langs);
$token = newToken();

llxHeader('', $langs->trans('FvFiscalMdfeListTitle'));

print load_fiche_titre($langs->trans('FvFiscalMdfeListTitle'), '', 'title_generic');

echo '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
echo '<table class="noborder" width="100%">';
echo '<tr class="liste_titre">';
echo '<th>' . $langs->trans('FvFiscalMdfeFiltersDate') . '</th>';
echo '<th>' . $langs->trans('FvFiscalMdfeFiltersStatus') . '</th>';
echo '<th>' . $langs->trans('FvFiscalMdfeFiltersDestination') . '</th>';
echo '<th>&nbsp;</th>';
echo '</tr>';
echo '<tr class="oddeven">';
echo '<td>';
echo '<input type="date" name="date_start" value="' . dol_escape_htmltag($dateStartInput) . '" class="flat">';
echo '&nbsp;';
echo '<input type="date" name="date_end" value="' . dol_escape_htmltag($dateEndInput) . '" class="flat">';
echo '</td>';
$selectStatus = '<select name="status" class="flat">';
$selectStatus .= '<option value="">&nbsp;</option>';
foreach ($statuses as $value => $label) {
    $selected = ((string) $value === (string) $statusFilter) ? ' selected' : '';
    $selectStatus .= '<option value="' . $value . '"' . $selected . '>' . dol_escape_htmltag($label) . '</option>';
}
$selectStatus .= '</select>';
echo '<td>' . $selectStatus . '</td>';
echo '<td><input type="text" name="destination_state" value="' . dol_escape_htmltag($destinationFilter) . '" class="flat" size="3" maxlength="2"></td>';
echo '<td class="right">';
echo '<input type="submit" class="button" value="' . $langs->trans('Search') . '">';
echo '&nbsp;<a class="butAction" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '">' . $langs->trans('FvFiscalReset') . '</a>';
echo '</td>';
echo '</tr>';
echo '</table>';
echo '</form>';

if ($confirmCancelId > 0) {
    echo '<div class="confirmmessage">';
    echo '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
    echo '<input type="hidden" name="token" value="' . newToken() . '">';
    echo '<input type="hidden" name="action" value="do_cancel">';
    echo '<input type="hidden" name="id" value="' . $confirmCancelId . '">';
    echo '<h3>' . $langs->trans('FvFiscalMdfeCancelConfirmTitle', $confirmCancelId) . '</h3>';
    echo '<p>' . $langs->trans('FvFiscalMdfeCancelConfirmQuestion', $confirmCancelId) . '</p>';
    echo '<div class="opacitymedium">' . $langs->trans('FvFiscalMdfeCancelWindowWarning') . '</div>';
    echo '<textarea name="justification" class="flat" rows="3" style="width:100%" required></textarea>';
    echo '<div class="center" style="margin-top:10px;">';
    echo '<button type="submit" class="butAction">' . $langs->trans('Validate') . '</button>';
    echo '&nbsp;<a class="button" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '">' . $langs->trans('Cancel') . '</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
}

if ($confirmCloseId > 0) {
    $existing = new FvMdfe($db);
    $existing->fetch($confirmCloseId);
    echo '<div class="confirmmessage">';
    echo '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
    echo '<input type="hidden" name="token" value="' . newToken() . '">';
    echo '<input type="hidden" name="action" value="do_close">';
    echo '<input type="hidden" name="id" value="' . $confirmCloseId . '">';
    echo '<h3>' . $langs->trans('FvFiscalMdfeActionClose') . '</h3>';
    echo '<p>' . $langs->trans('FvFiscalMdfeCloseConfirm', $confirmCloseId) . '</p>';
    $defaultClosureDate = !empty($existing->closure_at) ? dol_print_date($existing->closure_at, '%Y-%m-%dT%H:%M') : '';
    echo '<div class="pair">';
    echo '<label>' . $langs->trans('FvFiscalMdfeClosureDate') . '</label>';
    echo '<input type="datetime-local" name="closure_date" class="flat" value="' . dol_escape_htmltag($defaultClosureDate) . '">';
    echo '</div>';
    echo '<div class="pair">';
    echo '<label>' . $langs->trans('FvFiscalMdfeClosureCity') . '</label>';
    echo '<input type="text" name="closure_city" class="flat" value="' . dol_escape_htmltag($existing->closure_city) . '">';
    echo '</div>';
    echo '<div class="pair">';
    echo '<label>' . $langs->trans('FvFiscalMdfeClosureState') . '</label>';
    echo '<input type="text" name="closure_state" class="flat" size="3" maxlength="2" value="' . dol_escape_htmltag($existing->closure_state) . '">';
    echo '</div>';
    echo '<div class="center" style="margin-top:10px;">';
    echo '<button type="submit" class="butAction">' . $langs->trans('Validate') . '</button>';
    echo '&nbsp;<a class="button" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '">' . $langs->trans('Cancel') . '</a>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
}

if (empty($records)) {
    echo '<div class="opacitymedium">' . $langs->trans('FvFiscalMdfeNoRecords') . '</div>';
} else {
    echo '<table class="noborder" width="100%">';
    echo '<tr class="liste_titre">';
    echo '<th>' . $langs->trans('Ref') . '</th>';
    echo '<th>' . $langs->trans('Status') . '</th>';
    echo '<th>' . $langs->trans('Date') . '</th>';
    echo '<th>' . $langs->trans('FvFiscalMdfeDestinationCity') . '</th>';
    echo '<th class="right">' . $langs->trans('TotalHT') . '</th>';
    echo '<th class="center">' . $langs->trans('Actions') . '</th>';
    echo '</tr>';
    foreach ($records as $row) {
        $issueAt = $row->issue_at ? dol_print_date($db->jdate($row->issue_at), 'dayhour') : '';
        $isAuthorized = ((int) $row->status === FvMdfe::STATUS_AUTHORIZED);
        $canCancel = false;
        if ($isAuthorized && !empty($row->issue_at)) {
            $issueTimestamp = $db->jdate($row->issue_at);
            $canCancel = ($issueTimestamp + (24 * 3600)) >= dol_now();
        }
        $canClose = $isAuthorized;
        $destination = trim(($row->destination_city ? $row->destination_city : '')); 
        if (!empty($row->destination_state)) {
            $destination = ($destination !== '' ? $destination . ' / ' : '') . strtoupper($row->destination_state);
        }
        $statusLabel = isset($statuses[(int) $row->status]) ? $statuses[(int) $row->status] : $langs->trans('Unknown');
        echo '<tr class="oddeven">';
        echo '<td><a href="' . dol_buildpath('/fvfiscal/mdfe_card.php', 1) . '?id=' . ((int) $row->rowid) . '">' . dol_escape_htmltag($row->ref ?: ('MDFe-' . $row->rowid)) . '</a></td>';
        echo '<td>' . dol_escape_htmltag($statusLabel) . '</td>';
        echo '<td>' . $issueAt . '</td>';
        echo '<td>' . dol_escape_htmltag($destination) . '</td>';
        echo '<td class="right">' . price($row->total_value) . '</td>';
        echo '<td class="center">';
        $actions = array();
        if (!empty($row->xml_path)) {
            $actions[] = '<a class="button small" href="' . dol_buildpath('/document.php', 1) . '?modulepart=fvfiscal&file=' . urlencode($row->xml_path) . '">' . $langs->trans('FvFiscalMdfeActionDownloadXml') . '</a>';
        }
        if (!empty($row->pdf_path)) {
            $actions[] = '<a class="button small" href="' . dol_buildpath('/document.php', 1) . '?modulepart=fvfiscal&file=' . urlencode($row->pdf_path) . '">' . $langs->trans('FvFiscalMdfeActionDownloadPdf') . '</a>';
        }
        if ($canCancel) {
            $actions[] = '<a class="button small" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '?action=cancel&id=' . ((int) $row->rowid) . '">' . $langs->trans('FvFiscalMdfeActionCancel') . '</a>';
        }
        if ($canClose) {
            $actions[] = '<a class="button small" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '?action=close&id=' . ((int) $row->rowid) . '">' . $langs->trans('FvFiscalMdfeActionClose') . '</a>';
        }
        echo implode('&nbsp;', $actions);
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if ($total > $limit) {
        echo '<div class="pagination">';
        $numPages = ceil($total / $limit);
        for ($i = 0; $i < $numPages; $i++) {
            $class = ($i === $page) ? 'class="button"' : 'class="button"';
            $url = dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '?page=' . $i . '&limit=' . $limit;
            if ($dateStartInput !== '') {
                $url .= '&date_start=' . urlencode($dateStartInput);
            }
            if ($dateEndInput !== '') {
                $url .= '&date_end=' . urlencode($dateEndInput);
            }
            if ($statusFilter !== '') {
                $url .= '&status=' . urlencode($statusFilter);
            }
            if ($destinationFilter !== '') {
                $url .= '&destination_state=' . urlencode($destinationFilter);
            }
            echo '<a ' . $class . ' href="' . $url . '">' . ($i + 1) . '</a>&nbsp;';
        }
        echo '</div>';
    }
}

echo '<div class="tabsAction">';
echo '<a class="butAction" href="' . dol_buildpath('/fvfiscal/mdfe_card.php', 1) . '?action=create">' . $langs->trans('New') . '</a>';
echo '</div>';

llxFooter();
$db->close();
