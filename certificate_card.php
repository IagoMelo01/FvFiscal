<?php
/*
 * Certificate management card for FvFiscal module.
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

require_once __DIR__ . '/class/FvCertificate.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/lib/fvfiscal.lib.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('fvfiscal@fvfiscal', 'admin'));

$action = GETPOST('action', 'aZ09');
$certificateId = GETPOST('id', 'int');

$form = new Form($db);
$object = new FvCertificate($db);

if ($certificateId > 0) {
    if ($object->fetch($certificateId) <= 0) {
        setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
        $action = 'create';
        $certificateId = 0;
    }
}

if ($action === 'add' || $action === 'update') {
    if (!dol_validateToken(GETPOST('token'))) {
        accessforbidden($langs->trans('FvFiscalInvalidToken'));
    }

    $isCreate = ($action === 'add');
    $ref = trim((string) GETPOST('ref', 'alphanohtml'));
    $label = trim((string) GETPOST('label', 'restricthtml'));
    $status = GETPOST('status', 'int');
    if ($status === null) {
        $status = 0;
    }
    $passwordInput = GETPOST('certificate_password', 'restricthtml');
    $passwordConfirm = GETPOST('certificate_password_confirm', 'restricthtml');

    $errors = array();
    if ($ref === '') {
        $errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Ref'));
    }
    if ($passwordInput !== $passwordConfirm) {
        $errors[] = $langs->trans('FvFiscalErrorCertificatePasswordMismatch');
    }

    $upload = $_FILES['certificate_file'] ?? null;
    $hasUpload = $upload && !empty($upload['tmp_name']);
    if ($isCreate && !$hasUpload) {
        $errors[] = $langs->trans('FvFiscalErrorCertificateRequired');
    }
    if ($hasUpload && $passwordInput === '') {
        $errors[] = $langs->trans('FvFiscalErrorCertificatePasswordRequired');
    }

    $existingPath = '';
    if (!$isCreate) {
        $existingPath = $object->certificate_path ? fvfiscal_resolve_certificate_path($object->certificate_path) : '';
    }

    $storedFile = null;
    if (empty($errors) && $hasUpload) {
        $storedFile = fvfiscal_store_certificate_upload($upload);
        if ($storedFile === null) {
            $errors[] = $langs->trans('FvFiscalErrorCertificateMoveFailed');
        }
    }

    $passwordToUse = $passwordInput;
    if (!$isCreate && $passwordToUse === '' && !empty($object->certificate_password)) {
        $passwordToUse = fvfiscal_decrypt_value($object->certificate_password);
    }

    $sourcePath = '';
    if ($storedFile !== null) {
        $sourcePath = fvfiscal_resolve_certificate_path($storedFile['path']);
    } elseif ($existingPath !== '') {
        $sourcePath = $existingPath;
    }

    $certificateInfo = null;
    if ($sourcePath !== '' && $passwordToUse !== '') {
        $certificateInfo = fvfiscal_parse_certificate($sourcePath, $passwordToUse);
        if ($certificateInfo === null) {
            $errors[] = $langs->trans('FvFiscalErrorCertificateInvalid');
        }
    } elseif ($sourcePath !== '' && $passwordToUse === '') {
        $errors[] = $langs->trans('FvFiscalErrorCertificatePasswordRequired');
    }

    if (empty($errors)) {
        $object->entity = $conf->entity;
        $object->ref = $ref;
        $object->label = $label;
        $object->status = (int) $status;
        if ($storedFile !== null) {
            $object->certificate_path = $storedFile['path'];
        }
        if ($passwordToUse !== '') {
            $object->certificate_password = fvfiscal_encrypt_value($passwordToUse);
        }
        if ($certificateInfo !== null) {
            if (!empty($certificateInfo['valid_to'])) {
                $object->certificate_expire_at = $db->idate((int) $certificateInfo['valid_to']);
            }
            if (!empty($certificateInfo['metadata'])) {
                $object->metadata_json = json_encode($certificateInfo['metadata']);
            }
        }

        if ($isCreate) {
            $object->created_at = dol_now();
            $id = $object->create($user);
            if ($id > 0) {
                setEventMessages($langs->trans('RecordSaved'), null);
                header('Location: ' . dol_buildpath('/fvfiscal/certificate_list.php', 1));
                exit;
            }
            $errors[] = $object->error ?: $langs->trans('Error');
        } else {
            $object->id = $certificateId;
            if ($object->update($user) > 0) {
                setEventMessages($langs->trans('RecordSaved'), null);
                header('Location: ' . dol_buildpath('/fvfiscal/certificate_list.php', 1));
                exit;
            } else {
                $errors[] = $object->error ?: $langs->trans('Error');
            }
        }
    }

    if (!empty($errors)) {
        if ($storedFile !== null) {
            $storedPath = fvfiscal_resolve_certificate_path($storedFile['path']);
            if ($storedPath !== '' && file_exists($storedPath)) {
                @unlink($storedPath);
            }
        }
        setEventMessages('', $errors, 'errors');
        $object->ref = $ref;
        $object->label = $label;
        $object->status = $status;
    }

    $action = $isCreate ? 'create' : 'edit';
}

llxHeader('', $langs->trans('FvFiscalCertificateCard'));

print load_fiche_titre($langs->trans('FvFiscalCertificateCard'), '', 'title_setup');

print '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
if (!empty($object->id)) {
    print '<input type="hidden" name="id" value="' . ((int) $object->id) . '">';
}
print '<input type="hidden" name="action" value="' . ($action === 'create' ? 'add' : 'update') . '">';

print '<table class="border centpercent">';
print '<tr><td class="fieldrequired">' . $langs->trans('Ref') . '</td><td><input type="text" name="ref" value="' . dol_escape_htmltag($object->ref) . '"></td></tr>';
print '<tr><td>' . $langs->trans('Label') . '</td><td><input type="text" name="label" value="' . dol_escape_htmltag($object->label) . '"></td></tr>';

$statusOptions = array(
    0 => $langs->trans('Draft'),
    1 => $langs->trans('Enabled'),
    2 => $langs->trans('Archived'),
);
print '<tr><td>' . $langs->trans('Status') . '</td><td>' . $form->selectarray('status', $statusOptions, $object->status, 0) . '</td></tr>';

print '<tr><td>' . $langs->trans('FvFiscalCertificateFile') . '</td><td><input type="file" name="certificate_file" accept=".p12,.pfx">';
if (!empty($object->certificate_path)) {
    print '<div class="small opacitymedium">' . dol_escape_htmltag($object->certificate_path) . '</div>';
}
print '</td></tr>';

print '<tr><td>' . $langs->trans('FvFiscalCertificatePassword') . '</td><td><input type="password" name="certificate_password" autocomplete="new-password"></td></tr>';
print '<tr><td>' . $langs->trans('FvFiscalCertificatePasswordConfirm') . '</td><td><input type="password" name="certificate_password_confirm" autocomplete="new-password"></td></tr>';

if (!empty($object->certificate_expire_at)) {
    $expiry = $db->jdate($object->certificate_expire_at);
    print '<tr><td>' . $langs->trans('Expiration') . '</td><td>' . ($expiry ? dol_print_date($expiry, 'day', 'tzuser') : '') . '</td></tr>';
}

if (!empty($object->metadata_json)) {
    $meta = json_decode($object->metadata_json, true);
    if (is_array($meta)) {
        print '<tr><td>' . $langs->trans('Metadata') . '</td><td><pre>' . dol_escape_htmltag(json_encode($meta, JSON_PRETTY_PRINT)) . '</pre></td></tr>';
    }
}

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button" value="' . $langs->trans('Save') . '">';
print ' <a class="button" href="' . dol_buildpath('/fvfiscal/certificate_list.php', 1) . '">' . $langs->trans('Cancel') . '</a>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
