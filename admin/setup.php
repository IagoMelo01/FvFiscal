<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025           SuperAdmin
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
 * \file    fvfiscal/admin/setup.php
 * \ingroup fvfiscal
 * \brief   FvFiscal setup page.
 */

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
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once '../lib/fvfiscal.lib.php';
require_once '../class/FvSefazProfile.class.php';
require_once '../class/FvCertificate.class.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var Conf $conf
 * @var User $user
 */

// Translations
$langs->loadLangs(array('admin', 'fvfiscal@fvfiscal'));

// Access control
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$form = new Form($db);

$profile = fvfiscal_fetch_active_sefaz_profile($db);
$defaultCertificateId = !empty($conf->global->FVFISCAL_DEFAULT_CERTIFICATE) ? (int) $conf->global->FVFISCAL_DEFAULT_CERTIFICATE : 0;
$importCertificateId = !empty($conf->global->FVFISCAL_IMPORT_CERTIFICATE_ID) ? (int) $conf->global->FVFISCAL_IMPORT_CERTIFICATE_ID : 0;
if ($defaultCertificateId <= 0 && (int) $profile->fk_certificate > 0) {
    $defaultCertificateId = (int) $profile->fk_certificate;
}
if ($importCertificateId <= 0) {
    $importCertificateId = $defaultCertificateId;
}

$currentFocusToken = '';
if (!empty($conf->global->FVFISCAL_FOCUS_TOKEN)) {
    $currentFocusToken = fvfiscal_decrypt_value($conf->global->FVFISCAL_FOCUS_TOKEN);
}

$currentFocusEndpoint = !empty($conf->global->FVFISCAL_FOCUS_ENDPOINT) ? $conf->global->FVFISCAL_FOCUS_ENDPOINT : '';
$scienceAuto = !empty($conf->global->FVFISCAL_IMPORT_SCIENCE_AUTO);
$scienceInterval = !empty($conf->global->FVFISCAL_IMPORT_CRON_MIN) ? (int) $conf->global->FVFISCAL_IMPORT_CRON_MIN : 15;

$certificateChoices = array(0 => $langs->trans('Select'));
$certificateMetadata = array();
$sqlCert = 'SELECT rowid, ref, label, certificate_expire_at, status FROM ' . MAIN_DB_PREFIX . "fv_certificate";
$sqlCert .= ' WHERE entity IN (' . getEntity('fv_certificate') . ')';
$sqlCert .= ' ORDER BY ref ASC';
$resCert = $db->query($sqlCert);
if ($resCert) {
    while ($obj = $db->fetch_object($resCert)) {
        $expiry = $db->jdate($obj->certificate_expire_at);
        $label = $obj->ref;
        if (!empty($obj->label)) {
            $label .= ' - ' . $obj->label;
        }
        if ($expiry > 0) {
            $label .= ' (' . $langs->trans('FvFiscalCertificateExpiresOn', dol_print_date($expiry, 'day', 'tzuser')) . ')';
        }
        if ((int) $obj->status === 0) {
            $label .= ' [' . $langs->trans('Disabled') . ']';
        }
        $certificateChoices[(int) $obj->rowid] = $label;
        $certificateMetadata[(int) $obj->rowid] = array('expiry' => $expiry, 'status' => (int) $obj->status);
    }
    $db->free($resCert);
}

$errors = array();
$messages = array();

if ($action === 'save') {
    if (!dol_validateToken(GETPOST('token'))) {
        accessforbidden($langs->trans('FvFiscalInvalidToken'));
    }

    $focusEndpoint = trim((string) GETPOST('focus_endpoint', 'restricthtml'));
    $focusTokenInput = trim((string) GETPOST('focus_token', 'restricthtml'));
    $focusTokenClear = GETPOST('focus_token_clear', 'int');
    $sefazEnvironment = trim((string) GETPOST('sefaz_environment', 'alpha'));
    $taxRegime = trim((string) GETPOST('tax_regime', 'alpha'));
    $taxRegimeDetail = trim((string) GETPOST('tax_regime_detail', 'alpha'));
    $defaultCertificateInput = (int) GETPOST('default_certificate_id', 'int');
    $importCertificateInput = (int) GETPOST('import_certificate_id', 'int');
    $scienceAutoInput = GETPOST('science_auto', 'int');
    $scienceIntervalInput = (int) GETPOST('science_interval', 'int');

    if ($importCertificateInput <= 0) {
        $importCertificateInput = $defaultCertificateInput;
    }

    if ($focusEndpoint === '' || !filter_var($focusEndpoint, FILTER_VALIDATE_URL)) {
        $errors[] = $langs->trans('FvFiscalErrorFocusEndpoint');
    }

    if (!empty($sefazEnvironment) && !in_array($sefazEnvironment, array('production', 'homologation'), true)) {
        $errors[] = $langs->trans('FvFiscalErrorInvalidEnvironment');
    }

    if ($scienceIntervalInput <= 0) {
        $scienceIntervalInput = 15;
    }

    $focusTokenToStore = $currentFocusToken;
    if (!empty($focusTokenInput)) {
        $focusTokenToStore = $focusTokenInput;
    } elseif (!empty($focusTokenClear)) {
        $focusTokenToStore = '';
    }

    foreach (array('default' => $defaultCertificateInput, 'import' => $importCertificateInput) as $key => $certificateId) {
        if ($certificateId <= 0) {
            continue;
        }
        if (!isset($certificateMetadata[$certificateId])) {
            $errors[] = $langs->trans('FvFiscalErrorCertificateSelection');
            continue;
        }
        $meta = $certificateMetadata[$certificateId];
        if (!empty($meta['expiry']) && $meta['expiry'] < dol_now()) {
            $errors[] = $langs->trans('FvFiscalErrorCertificateExpired');
        }
        if ((int) $meta['status'] === 0) {
            $errors[] = $langs->trans('FvFiscalErrorCertificateDisabled');
        }
    }

    $focusStatusOk = false;
    if (empty($errors)) {
        $focusStatus = fvfiscal_ping_focus_status($focusEndpoint, $focusTokenToStore, $langs);
        if (!$focusStatus['success']) {
            $errors[] = $focusStatus['message'];
        } else {
            $focusStatusOk = true;
        }
    }

    if (empty($errors)) {
        $db->begin();

        $profile->entity = $conf->entity;
        $profile->status = 1;
        if (empty($profile->ref)) {
            $profile->ref = 'DEFAULT';
        }
        if (empty($profile->name)) {
            $profile->name = $langs->transnoentities('FvFiscalDefaultProfileName');
        }
        $profile->environment = $sefazEnvironment ?: 'production';
        $profile->tax_regime = $taxRegime;
        $profile->tax_regime_detail = $taxRegimeDetail;
        $profile->fk_certificate = $defaultCertificateInput ?: null;

        if ($profile->id > 0) {
            $result = $profile->update($user);
        } else {
            $result = $profile->create($user);
        }

        if ($result <= 0) {
            $db->rollback();
            $errors[] = $profile->error ?: $langs->trans('Error');
        } else {
            $focusTokenEncrypted = $focusTokenToStore !== '' ? fvfiscal_encrypt_value($focusTokenToStore) : '';
            if ($focusTokenEncrypted === '') {
                dolibarr_del_const($db, 'FVFISCAL_FOCUS_TOKEN', $conf->entity);
                unset($conf->global->FVFISCAL_FOCUS_TOKEN);
            } else {
                dolibarr_set_const($db, 'FVFISCAL_FOCUS_TOKEN', $focusTokenEncrypted, 'chaine', 0, '', $conf->entity);
                $conf->global->FVFISCAL_FOCUS_TOKEN = $focusTokenEncrypted;
            }

            dolibarr_set_const($db, 'FVFISCAL_FOCUS_ENDPOINT', $focusEndpoint, 'chaine', 0, '', $conf->entity);
            $conf->global->FVFISCAL_FOCUS_ENDPOINT = $focusEndpoint;

            if ($defaultCertificateInput > 0) {
                dolibarr_set_const($db, 'FVFISCAL_DEFAULT_CERTIFICATE', $defaultCertificateInput, 'chaine', 0, '', $conf->entity);
                $conf->global->FVFISCAL_DEFAULT_CERTIFICATE = $defaultCertificateInput;
            } else {
                dolibarr_del_const($db, 'FVFISCAL_DEFAULT_CERTIFICATE', $conf->entity);
                unset($conf->global->FVFISCAL_DEFAULT_CERTIFICATE);
            }

            if ($importCertificateInput > 0) {
                dolibarr_set_const($db, 'FVFISCAL_IMPORT_CERTIFICATE_ID', $importCertificateInput, 'chaine', 0, '', $conf->entity);
                $conf->global->FVFISCAL_IMPORT_CERTIFICATE_ID = $importCertificateInput;
            } else {
                dolibarr_del_const($db, 'FVFISCAL_IMPORT_CERTIFICATE_ID', $conf->entity);
                unset($conf->global->FVFISCAL_IMPORT_CERTIFICATE_ID);
            }

            dolibarr_set_const($db, 'FVFISCAL_IMPORT_SCIENCE_AUTO', $scienceAutoInput ? '1' : '0', 'chaine', 0, '', $conf->entity);
            $conf->global->FVFISCAL_IMPORT_SCIENCE_AUTO = $scienceAutoInput ? '1' : '';

            dolibarr_set_const($db, 'FVFISCAL_IMPORT_CRON_MIN', $scienceIntervalInput, 'chaine', 0, '', $conf->entity);
            $conf->global->FVFISCAL_IMPORT_CRON_MIN = $scienceIntervalInput;

            $db->commit();

            $messages[] = $langs->trans('FvFiscalSetupSaved');
            if ($focusStatusOk) {
                $messages[] = $langs->trans('FvFiscalFocusStatusOk');
            }

            $profile = fvfiscal_fetch_active_sefaz_profile($db);
            $currentFocusToken = $focusTokenToStore;
            $currentFocusEndpoint = $focusEndpoint;
            $scienceAuto = (bool) $scienceAutoInput;
            $scienceInterval = $scienceIntervalInput;
            $defaultCertificateId = $defaultCertificateInput;
            $importCertificateId = $importCertificateInput;
        }
    }

    if (!empty($errors)) {
        setEventMessages('', $errors, 'errors');
    }
    if (!empty($messages)) {
        setEventMessages('', $messages);
    }
}

llxHeader('', $langs->trans('FvFiscalSetup'));

$head = fvfiscalAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('FvFiscalSetup'));

print load_fiche_titre($langs->trans('FvFiscalSetupPage'), '', 'title_setup');

$certificateManageUrl = dol_buildpath('/fvfiscal/certificate_list.php', 1);

$defaultCertificateInfo = '';
if ($defaultCertificateId > 0 && isset($certificateMetadata[$defaultCertificateId])) {
    $meta = $certificateMetadata[$defaultCertificateId];
    if (!empty($meta['expiry'])) {
        $defaultCertificateInfo .= $langs->trans('FvFiscalCertificateValidUntil', dol_print_date($meta['expiry'], 'day', 'tzuser'));
    }
    if ((int) $meta['status'] === 0) {
        $defaultCertificateInfo .= ' - ' . $langs->trans('Disabled');
    }
} else {
    $defaultCertificateInfo = $langs->trans('FvFiscalCertificateNotSelected');
}

$importCertificateInfo = '';
if ($importCertificateId > 0 && isset($certificateMetadata[$importCertificateId])) {
    $meta = $certificateMetadata[$importCertificateId];
    if (!empty($meta['expiry'])) {
        $importCertificateInfo .= $langs->trans('FvFiscalCertificateValidUntil', dol_print_date($meta['expiry'], 'day', 'tzuser'));
    }
    if ((int) $meta['status'] === 0) {
        $importCertificateInfo .= ' - ' . $langs->trans('Disabled');
    }
} else {
    $importCertificateInfo = $langs->trans('FvFiscalCertificateFallbackNotice');
}

print '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('FvFiscalCertificateSection') . '</th></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalCertificateManage') . '</td>';
print '<td>' . sprintf($langs->trans('FvFiscalCertificateManageHelp'), '<a href="' . htmlspecialchars($certificateManageUrl) . '" class="button">' . $langs->trans('FvFiscalOpenCertificateManager') . '</a>') . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalDefaultCertificate') . '</td>';
print '<td>';
print $form->selectarray('default_certificate_id', $certificateChoices, $defaultCertificateId, 1);
print '<div class="small opacitymedium">' . dol_escape_htmltag($defaultCertificateInfo) . '</div>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalImportCertificate') . '</td>';
print '<td>';
print $form->selectarray('import_certificate_id', $certificateChoices, $importCertificateId, 1);
print '<div class="small opacitymedium">' . dol_escape_htmltag($importCertificateInfo) . '</div>';
print '</td>';
print '</tr>';

print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('FvFiscalSefazSection') . '</th></tr>';

$environmentOptions = array(
    'production' => $langs->trans('FvFiscalSefazEnvironmentProduction'),
    'homologation' => $langs->trans('FvFiscalSefazEnvironmentHomologation'),
);
print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalSefazEnvironment') . '</td>';
print '<td>' . $form->selectarray('sefaz_environment', $environmentOptions, $profile->environment ?: 'production', 0, 0, 0, '', 0, 0, 0, '', '');
print '</td>';
print '</tr>';

$taxRegimeOptions = array(
    '' => $langs->trans('Select'),
    '1' => $langs->trans('FvFiscalTaxRegime1'),
    '2' => $langs->trans('FvFiscalTaxRegime2'),
    '3' => $langs->trans('FvFiscalTaxRegime3'),
);
print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalTaxRegime') . '</td>';
print '<td>' . $form->selectarray('tax_regime', $taxRegimeOptions, $profile->tax_regime, 0, 0, 0, '', 0, 0, 0, '', '') . '</td>';
print '</tr>';

$taxRegimeDetailOptions = array(
    '' => $langs->trans('Select'),
    'mei' => $langs->trans('FvFiscalTaxRegimeDetailMei'),
    'sn_excess' => $langs->trans('FvFiscalTaxRegimeDetailSnExcess'),
    'lucro_presumido' => $langs->trans('FvFiscalTaxRegimeDetailPresumido'),
    'lucro_real' => $langs->trans('FvFiscalTaxRegimeDetailReal'),
);
print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalTaxRegimeDetail') . '</td>';
print '<td>' . $form->selectarray('tax_regime_detail', $taxRegimeDetailOptions, $profile->tax_regime_detail, 0, 0, 0, '', 0, 0, 0, '', '') . '</td>';
print '</tr>';

print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('FvFiscalFocusSection') . '</th></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalFocusEndpoint') . '</td>';
print '<td><input type="text" name="focus_endpoint" class="minwidth400" value="' . dol_escape_htmltag($currentFocusEndpoint) . '" placeholder="https://api.focusnfe.com.br"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalFocusToken') . '</td>';
print '<td>';
print '<input type="password" name="focus_token" class="minwidth400" autocomplete="new-password" value="">';
if ($currentFocusToken !== '') {
    print '<div class="small opacitymedium">' . $langs->trans('FvFiscalFocusTokenHelp') . '</div>';
    print '<div><label class="inline-block"><input type="checkbox" name="focus_token_clear" value="1"> ' . $langs->trans('FvFiscalFocusTokenClear') . '</label></div>';
}
print '</td>';
print '</tr>';

print '<tr class="liste_titre"><th colspan="2">' . $langs->trans('FvFiscalScienceSection') . '</th></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalScienceAuto') . '</td>';
print '<td><label><input type="checkbox" name="science_auto" value="1"' . ($scienceAuto ? ' checked' : '') . '> ' . $langs->trans('FvFiscalScienceAutoHelp') . '</label></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('FvFiscalScienceInterval') . '</td>';
print '<td><input type="number" name="science_interval" min="5" max="1440" value="' . (int) $scienceInterval . '"> ' . $langs->trans('Minutes') . '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<div class="center">';
print '<input type="submit" class="button" value="' . $langs->trans('Save') . '">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();

/**
 * Fetch the active SEFAZ profile for the current entity.
 *
 * @param DoliDB $db
 * @return FvSefazProfile
 */
function fvfiscal_fetch_active_sefaz_profile($db)
{
    $profile = new FvSefazProfile($db);

    $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_sefaz_profile";
    $sql .= ' WHERE entity IN (' . getEntity('fv_sefaz_profile') . ')';
    $sql .= ' ORDER BY status DESC, rowid ASC LIMIT 1';

    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        if ($obj) {
            $profile->fetch((int) $obj->rowid);
        }
    }

    return $profile;
}

/**
 * Parse PKCS#12 certificate to validate password and expiration.
 *
 * @param string    $path
 * @param string    $password
 * @return array{valid_to:int}|null
 */
/**
 * Ping Focus API status endpoint.
 *
 * @param string    $endpoint
 * @param string    $token
 * @param Translate $langs
 * @return array{success:bool,message:string}
 */
function fvfiscal_ping_focus_status($endpoint, $token, $langs)
{
    if (!function_exists('curl_init')) {
        return array('success' => false, 'message' => $langs->trans('FvFiscalErrorCurlMissing'));
    }

    $url = rtrim($endpoint, '/') . '/v2/status';
    $headers = array('Accept: application/json');
    $token = trim((string) $token);
    if ($token !== '') {
        if (stripos($token, 'bearer ') === 0 || stripos($token, 'basic ') === 0) {
            $headers[] = 'Authorization: ' . $token;
        } else {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
    }

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
    ));

    $content = curl_exec($curl);
    if ($content === false) {
        $message = curl_error($curl);
        $code = curl_errno($curl);
        curl_close($curl);

        return array('success' => false, 'message' => $langs->trans('FvFiscalErrorFocusStatusCurl', $code, $message));
    }

    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode >= 400) {
        return array('success' => false, 'message' => $langs->trans('FvFiscalErrorFocusStatusHttp', $httpCode));
    }

    if ($content === '' || $content === null) {
        return array('success' => true, 'message' => $langs->trans('FvFiscalFocusStatusOk'));
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        if (!empty($decoded['status']) && strtolower((string) $decoded['status']) !== 'ok') {
            $status = (string) $decoded['status'];
            return array('success' => false, 'message' => $langs->trans('FvFiscalErrorFocusStatusPayload', $status));
        }
    }

    return array('success' => true, 'message' => $langs->trans('FvFiscalFocusStatusOk'));
}
