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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once __DIR__ . '/class/FvMdfe.class.php';
require_once __DIR__ . '/class/FvNfeOut.class.php';
require_once __DIR__ . '/class/FvSefazProfile.class.php';
require_once __DIR__ . '/lib/fvfiscal_permissions.php';
require_once __DIR__ . '/lib/fvfiscal_mdfe_focus_service.class.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('fvfiscal@fvfiscal', 'companies', 'other', 'bills'));

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
    FvFiscalPermissions::BATCH_WRITE
)) {
    accessforbidden();
}

$form = new Form($db);
$formOther = new FormOther($db);

$mdfe = new FvMdfe($db);
if ($id > 0) {
    if ($mdfe->fetch($id) <= 0) {
        setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
        $id = 0;
    }
}

if ($action === 'create') {
    $id = 0;
}

if ($action === 'save') {
    $token = GETPOST('token', 'alpha');
    if (!dol_verify_token($token)) {
        accessforbidden();
    }

    $isNew = ($id <= 0);
    if ($isNew) {
        $mdfe = new FvMdfe($db);
    }

    $mdfe->ref = trim((string) GETPOST('ref', 'alphanohtml'));
    $profileId = GETPOSTINT('fk_sefaz_profile');
    if ($profileId > 0) {
        $mdfe->fk_sefaz_profile = $profileId;
    }
    $mdfe->vehicle_plate = dol_strtoupper(trim((string) GETPOST('vehicle_plate', 'alphanohtml')));
    $mdfe->vehicle_rntrc = trim((string) GETPOST('vehicle_rntrc', 'alphanohtml'));
    $mdfe->driver_name = trim((string) GETPOST('driver_name', 'alphanohtml'));
    $mdfe->driver_document = preg_replace('/[^0-9A-Za-z]/', '', (string) GETPOST('driver_document', 'alphanohtml'));
    $mdfe->origin_city = trim((string) GETPOST('origin_city', 'alphanohtml'));
    $mdfe->origin_state = dol_strtoupper(trim((string) GETPOST('origin_state', 'alpha')));
    $mdfe->destination_city = trim((string) GETPOST('destination_city', 'alphanohtml'));
    $mdfe->destination_state = dol_strtoupper(trim((string) GETPOST('destination_state', 'alpha')));
    $closureDate = trim((string) GETPOST('closure_date', 'alphanohtml'));
    if ($closureDate !== '') {
        $parsed = dol_stringtotime(str_replace('T', ' ', $closureDate));
        $mdfe->closure_at = $parsed > 0 ? $parsed : null;
    } else {
        $mdfe->closure_at = null;
    }
    $mdfe->closure_city = trim((string) GETPOST('closure_city', 'alphanohtml'));
    $mdfe->closure_state = dol_strtoupper(trim((string) GETPOST('closure_state', 'alpha')));

    $payload = array(
        'vehicle' => array(
            'placa' => $mdfe->vehicle_plate,
            'rntrc' => $mdfe->vehicle_rntrc,
        ),
        'driver' => array(
            'nome' => $mdfe->driver_name,
            'documento' => $mdfe->driver_document,
        ),
        'origin' => array(
            'municipio' => $mdfe->origin_city,
            'uf' => $mdfe->origin_state,
        ),
        'destination' => array(
            'municipio' => $mdfe->destination_city,
            'uf' => $mdfe->destination_state,
        ),
        'closure' => array(
            'data_encerramento' => $mdfe->closure_at ? dol_print_date($mdfe->closure_at, '%Y-%m-%dT%H:%M:%S') : '',
            'municipio' => $mdfe->closure_city,
            'uf' => $mdfe->closure_state,
        ),
    );
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson !== false) {
        $mdfe->json_payload = $payloadJson;
    }

    $selectedNfes = GETPOST('linked_nfe', 'array');

    if ($isNew) {
        $mdfe->entity = $conf->entity;
        $mdfe->status = FvMdfe::STATUS_DRAFT;
        if ($mdfe->create($user) > 0) {
            $id = $mdfe->id;
        } else {
            setEventMessages($mdfe->error ?: 'ErrorFailToCreate', $mdfe->errors, 'errors');
        }
    }

    if ($mdfe->id > 0) {
        if (!$isNew) {
            if ($mdfe->update($user) < 0) {
                setEventMessages($mdfe->error ?: 'ErrorFailToUpdate', $mdfe->errors, 'errors');
            }
        }
        if ($mdfe->id > 0) {
            $syncResult = $mdfe->syncLinkedNfes(is_array($selectedNfes) ? $selectedNfes : array());
            if ($syncResult < 0) {
                $errorKey = $mdfe->error ?: 'Error';
                $translated = $langs->transnoentitiesnoconv($errorKey);
                $message = ($translated !== $errorKey) ? $langs->trans($errorKey) : $errorKey;
                setEventMessages($message, null, 'errors');
            } else {
                $mdfe->update($user);
                setEventMessages($langs->trans('FvFiscalMdfeSaveSuccess'), null, 'mesgs');
                header('Location: ' . dol_buildpath('/fvfiscal/mdfe_card.php', 1) . '?id=' . $mdfe->id);
                exit;
            }
        }
    }
}

if ($action === 'focus_submit' && $mdfe->id > 0) {
    $token = GETPOST('token', 'alpha');
    if (!dol_verify_token($token)) {
        accessforbidden();
    }

    $service = new FvMdfeFocusService($db, $conf, $langs);
    $result = $service->createManifest($mdfe, $user);
    if ($result instanceof FvMdfe) {
        setEventMessages($langs->trans('FvFiscalMdfeFocusCreateSuccess'), null, 'mesgs');
        header('Location: ' . dol_buildpath('/fvfiscal/mdfe_card.php', 1) . '?id=' . $mdfe->id);
        exit;
    }
    setEventMessages($langs->trans('FvFiscalMdfeFocusError', $service->error), $service->errors, 'errors');
    $mdfe->fetch($mdfe->id);
}

if ($mdfe->id > 0) {
    $mdfe->fetch($mdfe->id);
}

$linkedIds = $mdfe->linked_nfe_ids;
if (empty($linkedIds) && $mdfe->id > 0) {
    $linkedIds = $mdfe->loadLinkedNfeIds();
}

$payload = array();
if (!empty($mdfe->json_payload)) {
    $decodedPayload = json_decode($mdfe->json_payload, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPayload)) {
        $payload = $decodedPayload;
    }
}

$availableNfes = array();
$sqlNfe = 'SELECT o.rowid, o.nfe_key, o.series, o.document_number, o.total_amount, s.nom as customer'
    . ' FROM ' . MAIN_DB_PREFIX . 'fv_nfe_out as o'
    . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'societe as s ON s.rowid = o.fk_soc'
    . ' WHERE o.entity IN (' . getEntity('fv_nfe_out') . ')'
    . ' AND o.status = ' . FvNfeOut::STATUS_AUTHORIZED;
if ($mdfe->id > 0) {
    $sqlNfe .= ' AND (NOT EXISTS ('
        . 'SELECT 1 FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe mn'
        . ' INNER JOIN ' . MAIN_DB_PREFIX . 'fv_mdfe md ON md.rowid = mn.fk_mdfe'
        . ' WHERE mn.fk_nfeout = o.rowid'
        . ' AND md.rowid <> ' . ((int) $mdfe->id)
        . ' AND md.status IN (' . FvMdfe::STATUS_DRAFT . ',' . FvMdfe::STATUS_PROCESSING . ',' . FvMdfe::STATUS_AUTHORIZED . '))'
        . ' OR o.rowid IN (' . implode(',', array_map('intval', $linkedIds ?: array(0))) . '))';
} else {
    $sqlNfe .= ' AND NOT EXISTS ('
        . 'SELECT 1 FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe mn'
        . ' INNER JOIN ' . MAIN_DB_PREFIX . 'fv_mdfe md ON md.rowid = mn.fk_mdfe'
        . ' WHERE mn.fk_nfeout = o.rowid'
        . ' AND md.status IN (' . FvMdfe::STATUS_DRAFT . ',' . FvMdfe::STATUS_PROCESSING . ',' . FvMdfe::STATUS_AUTHORIZED . '))';
}
$sqlNfe .= ' ORDER BY o.issue_at DESC';
$resNfe = $db->query($sqlNfe);
if ($resNfe) {
    while ($obj = $db->fetch_object($resNfe)) {
        $availableNfes[] = array(
            'id' => (int) $obj->rowid,
            'key' => $obj->nfe_key,
            'series' => $obj->series,
            'number' => $obj->document_number,
            'customer' => $obj->customer,
            'amount' => price2num($obj->total_amount, 'MT'),
        );
    }
    $db->free($resNfe);
}

$token = newToken();

llxHeader('', $langs->trans('FvFiscalMdfeCardTitle'));

$headerTitle = $mdfe->ref !== '' ? $mdfe->ref : $langs->trans('FvFiscalMdfeCardTitle');
print load_fiche_titre($headerTitle, '', 'title_generic');

echo '<div class="fiche">';

echo '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">';
echo '<input type="hidden" name="token" value="' . $token . '">';
if ($mdfe->id > 0) {
    echo '<input type="hidden" name="id" value="' . ((int) $mdfe->id) . '">';
}
echo '<input type="hidden" name="action" value="save">';

echo '<table class="border" width="100%">';

echo '<tr><td class="titlefield">' . $langs->trans('Ref') . '</td>';
$refInput = '<input type="text" name="ref" value="' . dol_escape_htmltag($mdfe->ref) . '" class="flat" size="32">';
echo '<td>' . $refInput . '</td>';
$labelStatus = $mdfe->id > 0 ? $mdfe->getStatusLabel($langs) : $langs->trans('FvFiscalMdfeStatusDraft');
echo '<td class="titlefield">' . $langs->trans('Status') . '</td><td>' . $labelStatus . '</td></tr>';

echo '<tr><td>' . $langs->trans('FvFiscalSefazProfile') . '</td><td>';
$profiles = array();
$sqlProfile = 'SELECT rowid, ref FROM ' . MAIN_DB_PREFIX . 'fv_sefaz_profile WHERE entity IN (' . getEntity('fv_sefaz_profile') . ') ORDER BY ref ASC';
$resProfile = $db->query($sqlProfile);
if ($resProfile) {
    while ($obj = $db->fetch_object($resProfile)) {
        $profiles[(int) $obj->rowid] = $obj->ref;
    }
    $db->free($resProfile);
}
echo $form->selectarray('fk_sefaz_profile', $profiles, $mdfe->fk_sefaz_profile, 1, 0, 0, '', 0, 0, 0, '', '', 1, '', '', '', 'maxwidth200');
echo '</td>';

echo '<td>' . $langs->trans('FvFiscalMdfeVehiclePlate') . '</td><td><input type="text" name="vehicle_plate" value="' . dol_escape_htmltag($mdfe->vehicle_plate) . '" class="flat" size="16"></td></tr>';

echo '<tr><td>' . $langs->trans('FvFiscalMdfeVehicleRntcr') . '</td><td><input type="text" name="vehicle_rntrc" value="' . dol_escape_htmltag($mdfe->vehicle_rntrc) . '" class="flat" size="20"></td>';

echo '<td>' . $langs->trans('FvFiscalMdfeDriverName') . '</td><td><input type="text" name="driver_name" value="' . dol_escape_htmltag($mdfe->driver_name) . '" class="flat" size="32"></td></tr>';

echo '<tr><td>' . $langs->trans('FvFiscalMdfeDriverDocument') . '</td><td><input type="text" name="driver_document" value="' . dol_escape_htmltag($mdfe->driver_document) . '" class="flat" size="20"></td>';

echo '<td>' . $langs->trans('FvFiscalMdfeOriginCity') . '</td><td><input type="text" name="origin_city" value="' . dol_escape_htmltag($mdfe->origin_city) . '" class="flat" size="32">';
echo '&nbsp;<input type="text" name="origin_state" value="' . dol_escape_htmltag($mdfe->origin_state) . '" class="flat" size="3" maxlength="2"></td></tr>';

echo '<tr><td>' . $langs->trans('FvFiscalMdfeDestinationCity') . '</td><td><input type="text" name="destination_city" value="' . dol_escape_htmltag($mdfe->destination_city) . '" class="flat" size="32">';
echo '&nbsp;<input type="text" name="destination_state" value="' . dol_escape_htmltag($mdfe->destination_state) . '" class="flat" size="3" maxlength="2"></td>';

echo '<td>' . $langs->trans('FvFiscalMdfeClosureDate') . '</td><td>'; 
$closureValue = '';
if (!empty($mdfe->closure_at)) {
    $closureValue = dol_print_date($mdfe->closure_at, '%Y-%m-%dT%H:%M');
} elseif (!empty($payload['closure']['data_encerramento'])) {
    $closureValue = substr($payload['closure']['data_encerramento'], 0, 16);
}
echo '<input type="datetime-local" name="closure_date" value="' . dol_escape_htmltag($closureValue) . '" class="flat">';
echo '</td></tr>';

echo '<tr><td>' . $langs->trans('FvFiscalMdfeClosureCity') . '</td><td><input type="text" name="closure_city" value="' . dol_escape_htmltag($mdfe->closure_city) . '" class="flat" size="32"></td>';
echo '<td>' . $langs->trans('FvFiscalMdfeClosureState') . '</td><td><input type="text" name="closure_state" value="' . dol_escape_htmltag($mdfe->closure_state) . '" class="flat" size="3" maxlength="2"></td></tr>';

echo '<tr><td class="tdtop">' . $langs->trans('FvFiscalMdfeLinkedDocuments') . '</td><td colspan="3">';
if (!empty($availableNfes)) {
    echo '<select name="linked_nfe[]" multiple size="8" class="flat" style="width:100%">';
    foreach ($availableNfes as $option) {
        $labelParts = array();
        if (!empty($option['series'])) {
            $labelParts[] = $option['series'];
        }
        if (!empty($option['number'])) {
            $labelParts[] = $option['number'];
        }
        $display = !empty($labelParts) ? implode('/', $labelParts) : $option['key'];
        if (!empty($option['customer'])) {
            $display .= ' - ' . $option['customer'];
        }
        if (!empty($option['amount'])) {
            $display .= ' (' . price($option['amount']) . ')';
        }
        $selected = in_array($option['id'], $linkedIds, true) ? ' selected' : '';
        echo '<option value="' . $option['id'] . '"' . $selected . '>' . dol_escape_htmltag($display) . '</option>';
    }
    echo '</select>';
    echo '<div class="opacitymedium">' . $langs->trans('FvFiscalMdfeLinkedDocumentsHelp') . '</div>';
} else {
    echo '<div class="opacitymedium">' . $langs->trans('None') . '</div>';
}
echo '</td></tr>';

echo '<tr><td>' . $langs->trans('TotalHT') . '</td><td>' . price($mdfe->total_value) . '</td>';
echo '<td>' . $langs->trans('Weight') . '</td><td>' . price($mdfe->total_weight) . '</td></tr>';

if (!empty($mdfe->mdfe_key)) {
    echo '<tr><td>' . $langs->trans('MdfeKey') . '</td><td>' . dol_escape_htmltag($mdfe->mdfe_key) . '</td>';
    echo '<td>' . $langs->trans('ProtocolNumber') . '</td><td>' . dol_escape_htmltag($mdfe->protocol_number) . '</td></tr>';
}

if (!empty($mdfe->xml_path) || !empty($mdfe->pdf_path)) {
    echo '<tr><td>' . $langs->trans('Documents') . '</td><td colspan="3">';
    $links = array();
    if (!empty($mdfe->xml_path)) {
        $links[] = '<a class="butAction" href="' . dol_buildpath('/document.php', 1) . '?modulepart=fvfiscal&file=' . urlencode($mdfe->xml_path) . '">' . $langs->trans('FvFiscalMdfeDownloadXml') . '</a>';
    }
    if (!empty($mdfe->pdf_path)) {
        $links[] = '<a class="butAction" href="' . dol_buildpath('/document.php', 1) . '?modulepart=fvfiscal&file=' . urlencode($mdfe->pdf_path) . '">' . $langs->trans('FvFiscalMdfeDownloadPdf') . '</a>';
    }
    echo implode('&nbsp;', $links);
    echo '</td></tr>';
}

echo '</table>';

echo '<div class="center" style="margin-top: 20px;">';
echo '<button type="submit" class="butAction">' . $langs->trans('Save') . '</button>';
$canSubmit = $mdfe->id > 0 && (int) $mdfe->status === FvMdfe::STATUS_DRAFT && !empty($linkedIds);
if ($canSubmit) {
    echo '&nbsp;<button type="submit" name="action" value="focus_submit" class="butAction">' . $langs->trans('FvFiscalMdfeActionSend') . '</button>';
}
echo '&nbsp;<a class="butAction" href="' . dol_buildpath('/fvfiscal/mdfe_list.php', 1) . '">' . $langs->trans('Cancel') . '</a>';
echo '</div>';

echo '</form>';

echo '</div>';

llxFooter();
$db->close();
