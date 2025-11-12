<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025           SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       fvfiscal/fvfiscalindex.php
 *      \ingroup    fvfiscal
 *      \brief      NF-e list page for outbound documents
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/class/FvNfeOut.class.php';
require_once __DIR__.'/lib/fvfiscal_permissions.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

// Load translation files required by the page
$langs->loadLangs(array('fvfiscal@fvfiscal', 'companies'));

$action = GETPOST('action', 'aZ09');

// Security check - Protection if external user
$socid = GETPOSTINT('socid');
if (!empty($user->socid) && $user->socid > 0) {
    $action = '';
    $socid = $user->socid;
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

$page = (int) GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$offset = $limit * $page;

$sortfield = GETPOST('sortfield', 'alphanohtml');
$allowedSortFields = array(
    'o.ref',
    'o.document_number',
    'o.series',
    'customer_name',
    'o.issue_at',
    'o.total_amount',
    'o.status',
);
if (!in_array($sortfield, $allowedSortFields, true)) {
    $sortfield = 'o.issue_at';
}
$sortfieldSql = ($sortfield === 'customer_name') ? 's.nom' : $sortfield;

$sortorder = strtoupper(GETPOST('sortorder', 'alpha'));
if ($sortorder !== 'ASC') {
    $sortorder = 'DESC';
}

$searchTerm = trim(GETPOST('search', 'alphanohtml'));

$statusRaw = GETPOST('status', 'alphanohtml');
$statusFilter = null;
if ($statusRaw !== '') {
    $statusFilter = (int) $statusRaw;
}

$dateStartInput = GETPOST('date_start', 'alphanohtml');
$dateEndInput = GETPOST('date_end', 'alphanohtml');

$dateStart = null;
if ($dateStartInput !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStartInput, $matchesStart)) {
    $dateStart = dol_mktime(0, 0, 0, (int) $matchesStart[2], (int) $matchesStart[3], (int) $matchesStart[1]);
}

$dateEnd = null;
if ($dateEndInput !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateEndInput, $matchesEnd)) {
    $dateEnd = dol_mktime(23, 59, 59, (int) $matchesEnd[2], (int) $matchesEnd[3], (int) $matchesEnd[1]);
}

$sqlFrom = ' FROM ' . MAIN_DB_PREFIX . "fv_nfe_out as o";
$sqlFrom .= ' LEFT JOIN ' . MAIN_DB_PREFIX . "societe as s ON s.rowid = o.fk_soc";

$where = array();
$where[] = 'o.entity IN (' . getEntity('fv_nfe_out') . ')';
if (!empty($socid)) {
    $where[] = 'o.fk_soc = ' . ((int) $socid);
}
if ($statusFilter !== null) {
    $where[] = 'o.status = ' . ((int) $statusFilter);
}
if ($dateStart !== null) {
    $where[] = "o.issue_at >= '" . $db->idate($dateStart) . "'";
}
if ($dateEnd !== null) {
    $where[] = "o.issue_at <= '" . $db->idate($dateEnd) . "'";
}
if ($searchTerm !== '') {
    $searchEscaped = $db->escape($searchTerm);
    $where[] = "(o.nfe_key LIKE '%" . $searchEscaped . "%'"
        . " OR o.ref LIKE '%" . $searchEscaped . "%'"
        . " OR o.document_number LIKE '%" . $searchEscaped . "%'"
        . " OR s.nom LIKE '%" . $searchEscaped . "%')";
}

$sqlWhere = ' WHERE ' . implode(' AND ', $where);

$sqlCount = 'SELECT COUNT(*) as total' . $sqlFrom . $sqlWhere;
$resql = $db->query($sqlCount);
$totalRecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    if ($obj) {
        $totalRecords = (int) $obj->total;
    }
    $db->free($resql);
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

$sqlSummary = 'SELECT o.status, COUNT(*) as doc_count, SUM(o.total_amount) as doc_total'
    . $sqlFrom . $sqlWhere . ' GROUP BY o.status';
$resqlSummary = $db->query($sqlSummary);
$summaryByStatus = array();
if ($resqlSummary) {
    while ($summaryRow = $db->fetch_object($resqlSummary)) {
        $statusKey = (int) $summaryRow->status;
        $summaryByStatus[$statusKey] = array(
            'count' => (int) $summaryRow->doc_count,
            'amount' => (float) ($summaryRow->doc_total ?: 0),
        );
    }
    $db->free($resqlSummary);
} elseif ($db->lasterror()) {
    setEventMessages($db->lasterror(), null, 'errors');
}

$summary = array(
    'authorized' => array('label' => $langs->trans('FvFiscalNfeOutSummaryAuthorized'), 'count' => 0, 'amount' => 0.0),
    'cancelled' => array('label' => $langs->trans('FvFiscalNfeOutSummaryCancelled'), 'count' => 0, 'amount' => 0.0),
    'pending' => array('label' => $langs->trans('FvFiscalNfeOutSummaryPending'), 'count' => 0, 'amount' => 0.0),
);

if (!empty($summaryByStatus[2])) {
    $summary['authorized']['count'] = $summaryByStatus[2]['count'];
    $summary['authorized']['amount'] = $summaryByStatus[2]['amount'];
}
if (!empty($summaryByStatus[4])) {
    $summary['cancelled']['count'] = $summaryByStatus[4]['count'];
    $summary['cancelled']['amount'] = $summaryByStatus[4]['amount'];
}
$pendingStatuses = array(0, 1);
foreach ($pendingStatuses as $pendingStatus) {
    if (!empty($summaryByStatus[$pendingStatus])) {
        $summary['pending']['count'] += $summaryByStatus[$pendingStatus]['count'];
        $summary['pending']['amount'] += $summaryByStatus[$pendingStatus]['amount'];
    }
}

$sqlSelect = 'SELECT o.rowid, o.ref, o.document_number, o.series, o.nfe_key, o.issue_at, o.total_amount, o.status, s.nom as customer_name'
    . $sqlFrom . $sqlWhere;
$sqlSelect .= ' ORDER BY ' . $sortfieldSql . ' ' . $sortorder;
$sqlSelect .= $db->plimit($limit, $offset);

$records = array();
$resql = $db->query($sqlSelect);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
    while ($obj = $db->fetch_object($resql)) {
        $records[] = array(
            'id' => (int) $obj->rowid,
            'ref' => $obj->ref,
            'document_number' => $obj->document_number,
            'series' => $obj->series,
            'nfe_key' => $obj->nfe_key,
            'issue_at' => $db->jdate($obj->issue_at),
            'total_amount' => (float) $obj->total_amount,
            'status' => (int) $obj->status,
            'customer_name' => $obj->customer_name,
        );
    }
    $db->free($resql);
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

$statusLabels = array(
    0 => $langs->trans('FvFiscalNfeOutStatusPending'),
    1 => $langs->trans('FvFiscalNfeOutStatusProcessing'),
    2 => $langs->trans('FvFiscalNfeOutStatusAuthorized'),
    3 => $langs->trans('FvFiscalNfeOutStatusError'),
    4 => $langs->trans('FvFiscalNfeOutStatusCancelled'),
);

$paramParts = array();
if ($statusFilter !== null) {
    $paramParts[] = 'status=' . urlencode((string) $statusFilter);
}
if ($dateStartInput !== '') {
    $paramParts[] = 'date_start=' . urlencode($dateStartInput);
}
if ($dateEndInput !== '') {
    $paramParts[] = 'date_end=' . urlencode($dateEndInput);
}
if ($searchTerm !== '') {
    $paramParts[] = 'search=' . urlencode($searchTerm);
}
if ($limit !== (empty($conf->liste_limit) ? 25 : (int) $conf->liste_limit)) {
    $paramParts[] = 'limit=' . urlencode((string) $limit);
}
if (!empty($socid)) {
    $paramParts[] = 'socid=' . urlencode((string) $socid);
}
$param = implode('&', $paramParts);

$nfeOutStatic = new FvNfeOut($db);

llxHeader('', $langs->trans('FvFiscalNfeOutListTitle'), '', '', 0, 0, '', '', '', 'mod-fvfiscal page-index');

$templateFile = __DIR__ . '/tpl/nfeout_list.tpl.php';
if (file_exists($templateFile)) {
    include $templateFile;
}

llxFooter();
$db->close();
