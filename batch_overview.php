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
 * \file       batch_overview.php
 * \ingroup    fvfiscal
 * \brief      Dashboard for batches and related records.
 */

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
require_once __DIR__.'/lib/fvfiscal_data_service.class.php';
require_once __DIR__.'/lib/fvfiscal_permissions.php';
require_once __DIR__.'/lib/llist.class.php';
require_once __DIR__.'/lib/fvfiscal_helpers.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */
/** @var Conf $conf */

$langs->loadLangs(array('fvfiscal@fvfiscal', 'companies', 'products'));

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

$action = GETPOST('action', 'aZ09');

if ($action === 'filter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fvfiscal_checkToken(GETPOST('token', 'alphanohtml'))) {
        setEventMessages($langs->trans('FvFiscalInvalidToken'), null, 'errors');
    } else {
        $socidValue = GETPOSTINT('socid_filter');
        $productValue = GETPOSTINT('productid_filter');
        if (!empty($user->socid)) {
            $socidValue = $user->socid;
        }

        $params = array();
        if ($socidValue > 0) {
            $params[] = 'socid='.(int) $socidValue;
        }
        if ($productValue > 0) {
            $params[] = 'productid='.(int) $productValue;
        }

        setEventMessages($langs->trans('FvFiscalFiltersApplied'), null, 'mesgs');
        header('Location: '.$_SERVER['PHP_SELF'].(!empty($params) ? '?'.implode('&', $params) : ''));
        exit;
    }
} elseif ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fvfiscal_checkToken(GETPOST('token', 'alphanohtml'))) {
        setEventMessages($langs->trans('FvFiscalInvalidToken'), null, 'errors');
    } else {
        $params = array();
        if (!empty($user->socid)) {
            $params[] = 'socid='.(int) $user->socid;
        }
        setEventMessages($langs->trans('FvFiscalFiltersCleared'), null, 'mesgs');
        header('Location: '.$_SERVER['PHP_SELF'].(!empty($params) ? '?'.implode('&', $params) : ''));
        exit;
    }
}

$socidFilter = GETPOSTINT('socid');
$productFilter = GETPOSTINT('productid');
$batchId = GETPOSTINT('id');

if (!empty($user->socid)) {
    $socidFilter = $user->socid;
}

$form = new Form($db);
$service = new FvFiscalDataService($db);

$batchList = $service->fetchBatches($socidFilter, $productFilter);
if ($batchList === false) {
    setEventMessages($service->getError(), $service->getErrors(), 'errors');
    $batchList = array();
}

if (empty($batchId) && !empty($batchList)) {
    $batchId = $batchList[0]['rowid'];
}

$batch = null;
$batchLines = array();
$batchEvents = array();
$batchJobs = array();
$batchDocuments = array();

if (!empty($batchId)) {
    $batch = $service->fetchBatchDetail($batchId);
    if ($batch === false) {
        setEventMessages($service->getError(), $service->getErrors(), 'errors');
        $batch = null;
    } elseif ($batch === null) {
        setEventMessages($langs->trans('FvFiscalBatchNotFound'), null, 'warnings');
    } else {
        $batchLines = $service->fetchBatchLines($batchId, $productFilter);
        if ($batchLines === false) {
            setEventMessages($service->getError(), $service->getErrors(), 'errors');
            $batchLines = array();
        }

        $batchEvents = $service->fetchBatchEvents($batchId);
        if ($batchEvents === false) {
            setEventMessages($service->getError(), $service->getErrors(), 'errors');
            $batchEvents = array();
        }

        $batchJobs = $service->fetchBatchJobs($batchId);
        if ($batchJobs === false) {
            setEventMessages($service->getError(), $service->getErrors(), 'errors');
            $batchJobs = array();
        }

        $batchDocuments = $service->fetchBatchDocuments($batchId, empty($batch['fk_focus_job']) ? 0 : $batch['fk_focus_job'], $productFilter);
        if ($batchDocuments === false) {
            setEventMessages($service->getError(), $service->getErrors(), 'errors');
            $batchDocuments = array();
        }
    }
}

$tokenFilter = newToken();

$baseUrl = dol_buildpath('/fvfiscal/batch_overview.php', 1);
$queryBase = array();
if ($socidFilter > 0) {
    $queryBase['socid'] = $socidFilter;
}
if ($productFilter > 0) {
    $queryBase['productid'] = $productFilter;
}

$batchListView = new llist('fvbatch-list');
$batchListView->setHeaders(array(
    array('label' => $langs->trans('FvFiscalBatchRef')),
    array('label' => $langs->trans('FvFiscalPartnerProfile')),
    array('label' => $langs->trans('Status'), 'align' => 'center'),
    array('label' => $langs->trans('FvFiscalScheduledAt'), 'align' => 'right'),
    array('label' => $langs->trans('FvFiscalRemoteStatus')),
));
$batchListView->setEmptyMessage($langs->trans('FvFiscalNoBatch'));

$batchRows = array();
foreach ($batchList as $row) {
    $linkParams = $queryBase;
    $linkParams['id'] = $row['rowid'];
    $rowUrl = $baseUrl.'?'.http_build_query($linkParams);

    $partnerCell = '';
    if (!empty($row['socid'])) {
        $partnerCell = '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $row['socid'].'">'.dol_escape_htmltag($row['soc_name']).'</a>';
    } else {
        $partnerCell = dol_escape_htmltag($row['soc_name']);
    }

    $scheduled = !empty($row['scheduled_for']) ? dol_print_date($row['scheduled_for'], 'dayhour') : '';

    $batchRows[] = array(
        'url' => $rowUrl,
        'active' => ($row['rowid'] == $batchId),
        'columns' => array(
            array('value' => $row['ref']),
            array('value' => $partnerCell, 'is_html' => true),
            array('value' => $row['status'], 'align' => 'center'),
            array('value' => $scheduled, 'align' => 'right'),
            array('value' => $row['remote_status']),
        ),
    );
}

$lineListView = new llist('fvbatch-lines');
$lineListView->setHeaders(array(
    array('label' => $langs->trans('LineNumber'), 'align' => 'center'),
    array('label' => $langs->trans('Type')),
    array('label' => $langs->trans('Status'), 'align' => 'center'),
    array('label' => $langs->trans('Product')),
    array('label' => $langs->trans('DateStart'), 'align' => 'right'),
    array('label' => $langs->trans('DateEnd'), 'align' => 'right'),
));
$lineListView->setEmptyMessage($langs->trans('FvFiscalNoBatchLines'));

$lineRows = array();
foreach ($batchLines as $line) {
    $productLabel = '';
    if (!empty($line['fk_product'])) {
        $productLabel = dol_escape_htmltag($line['product_ref']);
        if (!empty($line['product_label'])) {
            $productLabel .= ' - '.dol_escape_htmltag($line['product_label']);
        }
    }

    $lineRows[] = array(
        'columns' => array(
            array('value' => $line['order_position'], 'align' => 'center'),
            array('value' => $line['line_type']),
            array('value' => $line['status'], 'align' => 'center'),
            array('value' => $productLabel, 'is_html' => true),
            array('value' => !empty($line['started_at']) ? dol_print_date($line['started_at'], 'dayhour') : '', 'align' => 'right'),
            array('value' => !empty($line['finished_at']) ? dol_print_date($line['finished_at'], 'dayhour') : '', 'align' => 'right'),
        ),
    );
}

$eventListView = new llist('fvbatch-events');
$eventListView->setHeaders(array(
    array('label' => $langs->trans('FvFiscalEventType')),
    array('label' => $langs->trans('Error'), 'align' => 'left'),
    array('label' => $langs->trans('DateCreation'), 'align' => 'right'),
));
$eventListView->setEmptyMessage($langs->trans('FvFiscalNoBatchEvents'));

$eventRows = array();
foreach ($batchEvents as $event) {
    $eventRows[] = array(
        'columns' => array(
            array('value' => $event['event_type']),
            array('value' => dol_trunc($event['error_message'], 120)),
            array('value' => !empty($event['datetime_created']) ? dol_print_date($event['datetime_created'], 'dayhour') : '', 'align' => 'right'),
        ),
    );
}

$jobListView = new llist('fvbatch-jobs');
$jobListView->setHeaders(array(
    array('label' => $langs->trans('Ref')),
    array('label' => $langs->trans('Type')),
    array('label' => $langs->trans('Status'), 'align' => 'center'),
    array('label' => $langs->trans('RemoteAccessCode')),
    array('label' => $langs->trans('DateStart'), 'align' => 'right'),
    array('label' => $langs->trans('DateEnd'), 'align' => 'right'),
));
$jobListView->setEmptyMessage($langs->trans('FvFiscalNoBatchJobs'));

$jobRows = array();
foreach ($batchJobs as $job) {
    $jobRows[] = array(
        'columns' => array(
            array('value' => $job['ref']),
            array('value' => $job['job_type']),
            array('value' => $job['remote_status'], 'align' => 'center'),
            array('value' => $job['remote_id']),
            array('value' => !empty($job['started_at']) ? dol_print_date($job['started_at'], 'dayhour') : '', 'align' => 'right'),
            array('value' => !empty($job['finished_at']) ? dol_print_date($job['finished_at'], 'dayhour') : '', 'align' => 'right'),
        ),
    );
}

$documentListView = new llist('fvbatch-documents');
$documentListView->setHeaders(array(
    array('label' => $langs->trans('Ref')),
    array('label' => $langs->trans('Type')),
    array('label' => $langs->trans('Status'), 'align' => 'center'),
    array('label' => $langs->trans('ThirdParty')),
    array('label' => $langs->trans('AmountTTC'), 'align' => 'right'),
    array('label' => $langs->trans('DateCreation'), 'align' => 'right'),
));
$documentListView->setEmptyMessage($langs->trans('FvFiscalNoBatchDocuments'));

$documentRows = array();
foreach ($batchDocuments as $document) {
    $thirdpartyCell = '';
    if (!empty($document['fk_soc'])) {
        $thirdpartyCell = '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $document['fk_soc'].'">'.dol_escape_htmltag($document['soc_name']).'</a>';
    } else {
        $thirdpartyCell = dol_escape_htmltag($document['soc_name']);
    }

    $documentRows[] = array(
        'columns' => array(
            array('value' => $document['ref']),
            array('value' => $document['doc_type']),
            array('value' => $document['status'], 'align' => 'center'),
            array('value' => $thirdpartyCell, 'is_html' => true),
            array('value' => price($document['total_amount']), 'align' => 'right', 'is_html' => true),
            array('value' => !empty($document['issue_at']) ? dol_print_date($document['issue_at'], 'dayhour') : '', 'align' => 'right'),
        ),
    );
}

llxHeader('', $langs->trans('FvFiscalBatchOverviewTitle'), '', '', 0, 0, '', '', '', 'mod-fvfiscal page-batch-overview');

print load_fiche_titre($langs->trans('FvFiscalBatchOverviewTitle'), '', 'fvfiscal.png@fvfiscal');

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="listform">';
print '<input type="hidden" name="token" value="'.$tokenFilter.'">';
print '<div class="tagtable">';
print '<div class="tagtr">';
print '<div class="tagtd">'.$langs->trans('FvFiscalFilterPartner').'</div>';
print '<div class="tagtd">'.$form->select_thirdparty_list($socidFilter, 'socid_filter', '', 1).'</div>';
print '</div>';
print '<div class="tagtr">';
print '<div class="tagtd">'.$langs->trans('FvFiscalFilterProduct').'</div>';
print '<div class="tagtd">'.$form->select_produits($productFilter, 'productid_filter', '', 1).'</div>';
print '</div>';
print '</div>';
print '<div class="center">';
print '<button type="submit" name="action" value="filter" class="button">'.$langs->trans('FvFiscalApplyFilters').'</button>';
print '<button type="submit" name="action" value="reset" class="button button-cancel">'.$langs->trans('FvFiscalReset').'</button>';
print '</div>';
print '</form>';

print $batchListView->render($batchRows);
print '</div>';

print '<div class="fichehalfright">';

$head = array(array($baseUrl.(!empty($queryBase) ? '?'.http_build_query($queryBase) : ''), $langs->trans('FvFiscalBatchDetailsTab'), 'overview'));
print dol_fiche_head($head, 'overview', !empty($batch['ref']) ? $batch['ref'] : $langs->trans('FvFiscalBatchDetailsTab'), -1, 'fvfiscal@fvfiscal');

if (!empty($batch)) {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($batch['ref']).'</td></tr>';
    print '<tr><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag($batch['status']).'</td></tr>';
    print '<tr><td>'.$langs->trans('FvFiscalBatchType').'</td><td>'.dol_escape_htmltag($batch['batch_type']).'</td></tr>';
    if (!empty($batch['fk_soc'])) {
        print '<tr><td>'.$langs->trans('ThirdParty').'</td><td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $batch['fk_soc'].'">'.dol_escape_htmltag($batch['soc_name']).'</a></td></tr>';
    }
    if (!empty($batch['remote_id'])) {
        print '<tr><td>'.$langs->trans('FvFiscalRemoteId').'</td><td>'.dol_escape_htmltag($batch['remote_id']).'</td></tr>';
    }
    if (!empty($batch['remote_status'])) {
        print '<tr><td>'.$langs->trans('FvFiscalRemoteStatus').'</td><td>'.dol_escape_htmltag($batch['remote_status']).'</td></tr>';
    }
    print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.(!empty($batch['started_at']) ? dol_print_date($batch['started_at'], 'dayhour') : '').'</td></tr>';
    print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.(!empty($batch['finished_at']) ? dol_print_date($batch['finished_at'], 'dayhour') : '').'</td></tr>';
    print '</table>';
    print '</div>';

    print '<div class="fichehalfright">';
    print '<table class="border centpercent">';
    if (!empty($batch['fk_sefaz_profile'])) {
        print '<tr><td class="titlefield">'.$langs->trans('FvFiscalSefazProfile').'</td><td>'.dol_escape_htmltag($batch['sefaz_ref']).'</td></tr>';
    }
    if (!empty($batch['fk_focus_job'])) {
        print '<tr><td>'.$langs->trans('FvFiscalFocusJob').'</td><td>'.dol_escape_htmltag($batch['focus_job_type']).' ('.dol_escape_htmltag($batch['focus_remote_id']).')</td></tr>';
    }
    print '<tr><td>'.$langs->trans('DateCreation').'</td><td>'.(!empty($batch['created_at']) ? dol_print_date($batch['created_at'], 'dayhour') : '').'</td></tr>';
    print '<tr><td>'.$langs->trans('DateModification').'</td><td>'.(!empty($batch['updated_at']) ? dol_print_date($batch['updated_at'], 'dayhour') : '').'</td></tr>';
    if (!empty($batch['settings_json'])) {
        print '<tr><td>'.$langs->trans('FvFiscalSettings').'</td><td><div class="maxheight200">'.dol_htmlentitiesbr($batch['settings_json']).'</div></td></tr>';
    }
    print '</table>';
    print '</div>';
    print '</div>';

    print '<br>';
    print load_fiche_titre($langs->trans('FvFiscalBatchLinesTitle'));
    print $lineListView->render($lineRows);

    print '<br>';
    print load_fiche_titre($langs->trans('FvFiscalBatchJobsTitle'));
    print $jobListView->render($jobRows);

    print '<br>';
    print load_fiche_titre($langs->trans('FvFiscalBatchEventsTitle'));
    print $eventListView->render($eventRows);

    print '<br>';
    print load_fiche_titre($langs->trans('FvFiscalBatchDocumentsTitle'));
    print $documentListView->render($documentRows);
} else {
    print '<div class="opacitymedium">'.$langs->trans('FvFiscalSelectBatchHint').'</div>';
}

dol_fiche_end();
print '</div>';
print '</div>';

llxFooter();
$db->close();
