<?php
/*
 * Certificate management list for FvFiscal module.
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

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('fvfiscal@fvfiscal', 'admin'));

$action = GETPOST('action', 'aZ09');

$form = new Form($db);

llxHeader('', $langs->trans('FvFiscalCertificateList'));

print load_fiche_titre($langs->trans('FvFiscalCertificateList'), '', 'title_setup');

$cardUrl = dol_buildpath('/fvfiscal/certificate_card.php', 1);
print '<div class="tabsAction">';
print '<a class="butAction" href="' . htmlspecialchars($cardUrl . '?action=create') . '">' . $langs->trans('New') . '</a>';
print '</div>';

$sql = 'SELECT rowid, ref, label, status, certificate_expire_at, certificate_path, created_at, updated_at';
$sql .= ' FROM ' . MAIN_DB_PREFIX . "fv_certificate";
$sql .= ' WHERE entity IN (' . getEntity('fv_certificate') . ')';
$sql .= ' ORDER BY ref ASC';

$resql = $db->query($sql);
if (!$resql) {
    setEventMessages($db->lasterror(), null, 'errors');
} else {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans('Ref') . '</th>';
    print '<th>' . $langs->trans('Label') . '</th>';
    print '<th>' . $langs->trans('Status') . '</th>';
    print '<th>' . $langs->trans('Expiration') . '</th>';
    print '<th>' . $langs->trans('Path') . '</th>';
    print '<th>' . $langs->trans('DateCreation') . '</th>';
    print '<th>' . $langs->trans('Tms') . '</th>';
    print '</tr>';

    $certificate = new FvCertificate($db);
    while ($obj = $db->fetch_object($resql)) {
        $href = $cardUrl . '?id=' . ((int) $obj->rowid);
        $statusKey = isset($certificate->statuts[(int) $obj->status]) ? $certificate->statuts[(int) $obj->status] : 'Status';
        $expiry = $db->jdate($obj->certificate_expire_at);
        $created = $db->jdate($obj->created_at);
        $updated = $db->jdate($obj->updated_at);

        print '<tr class="oddeven">';
        print '<td><a href="' . htmlspecialchars($href) . '">' . dol_escape_htmltag($obj->ref) . '</a></td>';
        print '<td>' . dol_escape_htmltag($obj->label) . '</td>';
        print '<td>' . $langs->trans($statusKey) . '</td>';
        if ($expiry > 0) {
            $class = $expiry < dol_now() ? ' class="error"' : '';
            print '<td' . $class . '>' . dol_print_date($expiry, 'day', 'tzuser') . '</td>';
        } else {
            print '<td><span class="opacitymedium">' . $langs->trans('Unknown') . '</span></td>';
        }
        print '<td>' . dol_escape_htmltag($obj->certificate_path) . '</td>';
        print '<td>' . ($created ? dol_print_date($created, 'day', 'tzuser') : '') . '</td>';
        print '<td>' . ($updated ? dol_print_date($updated, 'day', 'tzuser') : '') . '</td>';
        print '</tr>';
    }
    print '</table>';
    $db->free($resql);
}

llxFooter();
$db->close();
