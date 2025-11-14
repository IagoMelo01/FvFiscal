<?php
/**
 * Template for outbound NF-e list rendering.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    exit;
}

global $conf, $form, $langs, $nfeOutStatic, $formconfirm, $canManageFocus, $param;

print load_fiche_titre($langs->trans('FvFiscalNfeOutListTitle'), '', 'fvfiscal.png@fvfiscal');

if (!empty($formconfirm)) {
    print $formconfirm;
}

print '<div class="fichecenter">';
print '<div class="box-flex">';
foreach ($summary as $item) {
    print '<div class="box-flex-element">';
    print '<div class="paddingtop paddingbottom">';
    print '<div class="opacitymedium text-small">' . dol_escape_htmltag($item['label']) . '</div>';
    print '<div class="fontsize-large bold">' . dol_escape_htmltag((string) $item['count']) . '</div>';
    print '<div class="opacitymedium">' . price($item['amount']) . '</div>';
    print '</div>';
    print '</div>';
}
print '</div>';
print '</div>';

print '<div class="fichecenter">';
print '<form method="get" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="listfilter">';

if (!empty($socid)) {
    print '<input type="hidden" name="socid" value="' . dol_escape_htmltag((string) $socid) . '">';
}
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';

print '<div class="inline-block marginrightonly">';
$statusOptions = array('' => $langs->trans('All'));
foreach ($statusLabels as $statusValue => $statusLabel) {
    $statusOptions[$statusValue] = $statusLabel;
}
print $form->selectarray('status', $statusOptions, ($statusFilter !== null ? $statusFilter : ''), 0, 0, 0, '', 0, 0, 0, '', '', 1);
print '</div>';

print '<div class="inline-block marginrightonly">';
print '<input type="date" name="date_start" class="flat" value="' . dol_escape_htmltag($dateStartInput) . '">';
print '</div>';

print '<div class="inline-block marginrightonly">';
print '<input type="date" name="date_end" class="flat" value="' . dol_escape_htmltag($dateEndInput) . '">';
print '</div>';

print '<div class="inline-block marginrightonly">';
print '<input type="text" name="search" class="flat" placeholder="' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutSearchPlaceholder')) . '" value="' . dol_escape_htmltag($searchTerm) . '">';
print '</div>';

print '<div class="inline-block">';
print '<button type="submit" class="button smallpaddingimp">' . dol_escape_htmltag($langs->trans('Search')) . '</button>';
$resetUrl = dol_escape_htmltag($_SERVER['PHP_SELF'] . (!empty($socid) ? '?socid=' . urlencode((string) $socid) : ''));
print '<a class="button button-cancel smallpaddingimp" href="' . $resetUrl . '">' . dol_escape_htmltag($langs->trans('Reset')) . '</a>';
print '</div>';

print '</form>';
print '</div>';

print '<div class="fichecenter">';

print_barre_liste(
    $langs->trans('FvFiscalNfeOutListTitle'),
    $page,
    $_SERVER['PHP_SELF'],
    $param,
    $sortfield,
    $sortorder,
    '',
    $num,
    $totalRecords,
    'title_generic'
);

print '<div class="div-table-responsive">';
print '<table class="liste">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'o.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('FvFiscalNfeOutNumber'), $_SERVER['PHP_SELF'], 'o.document_number', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('FvFiscalNfeOutRecipient'), $_SERVER['PHP_SELF'], 'customer_name', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'o.issue_at', '', $param, '', $sortfield, $sortorder, 'center');
print_liste_field_titre($langs->trans('AmountTTC'), $_SERVER['PHP_SELF'], 'o.total_amount', '', $param, '', $sortfield, $sortorder, 'right');
print_liste_field_titre($langs->trans('Status'), $_SERVER['PHP_SELF'], 'o.status', '', $param, '', $sortfield, $sortorder, 'center');
if (!empty($canManageFocus)) {
    print '<th class="center">' . dol_escape_htmltag($langs->trans('Actions')) . '</th>';
}
print '</tr>';

foreach ($records as $record) {
    $nfeOutStatic->id = $record['id'];
    $nfeOutStatic->ref = $record['ref'];
    print '<tr class="oddeven">';
    print '<td class="nowrap">' . $nfeOutStatic->getNomUrl(1, '', 0, 0, 0, '') . '</td>';
    $numberParts = array();
    if (!empty($record['series'])) {
        $numberParts[] = $record['series'];
    }
    if (!empty($record['document_number'])) {
        $numberParts[] = $record['document_number'];
    }
    $numberDisplay = trim(implode(' ', $numberParts));
    if ($numberDisplay === '' && !empty($record['ref'])) {
        $numberDisplay = $record['ref'];
    }
    print '<td class="nowrap">';
    print dol_escape_htmltag($numberDisplay);
    if (!empty($record['nfe_key'])) {
        print '<div class="opacitymedium text-small">' . dol_escape_htmltag($record['nfe_key']) . '</div>';
    }
    print '</td>';
    print '<td>' . dol_escape_htmltag($record['customer_name']) . '</td>';
    print '<td class="center">' . ($record['issue_at'] ? dol_print_date($record['issue_at'], 'day') : '') . '</td>';
    print '<td class="right">' . price($record['total_amount']) . '</td>';
    $statusValue = isset($statusLabels[$record['status']]) ? $statusLabels[$record['status']] : $langs->trans('Unknown');
    print '<td class="center">' . dol_escape_htmltag($statusValue) . '</td>';
    if (!empty($canManageFocus)) {
        print '<td class="center nowrap">';
        $actions = array();
        $baseUrl = $_SERVER['PHP_SELF'];
        $query = $param !== '' ? $param . '&' : '';
        $query .= 'nfe_id=' . ((int) $record['id']);
        if ((int) $record['status'] === FvNfeOut::STATUS_DRAFT) {
            $actions[] = '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($baseUrl . '?action=issue_nfe&' . $query) . '">' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutActionIssue')) . '</a>';
            if (!empty($record['focus_job_id']) || !empty($record['has_payload']) || !empty($record['has_response'])) {
                $actions[] = '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($baseUrl . '?action=reprocess_nfe&' . $query) . '">' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutActionReprocess')) . '</a>';
            }
        } elseif ((int) $record['status'] === FvNfeOut::STATUS_AUTHORIZED) {
            $actions[] = '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($baseUrl . '?action=cancel_nfe&' . $query) . '">' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutActionCancel')) . '</a>';
            $actions[] = '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($baseUrl . '?action=send_cce&' . $query) . '">' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutActionCce')) . '</a>';
        }
        if (empty($actions)) {
            print '<span class="opacitymedium">-</span>';
        } else {
            print implode(' ', $actions);
        }
        print '</td>';
    }
    print '</tr>';
}

if (empty($records)) {
    $colspan = !empty($canManageFocus) ? 7 : 6;
    print '<tr class="oddeven"><td colspan="' . ((int) $colspan) . '" class="opacitymedium">' . dol_escape_htmltag($langs->trans('FvFiscalNfeOutEmpty')) . '</td></tr>';
}

print '</table>';
print '</div>';
print '</div>';
?>
