<?php
/* Copyright (C) 2025		SuperAdmin
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
 * \file    fvfiscal/lib/fvfiscal.lib.php
 * \ingroup fvfiscal
 * \brief   Library files with common functions for FvFiscal
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function fvfiscalAdminPrepareHead()
{
        global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("fvfiscal@fvfiscal");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/fvfiscal/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/fvfiscal/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['myobject']['label']) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/fvfiscal/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@fvfiscal:/fvfiscal/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@fvfiscal:/fvfiscal/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'fvfiscal@fvfiscal');

        complete_head_from_modules($conf, $langs, null, $head, $h, 'fvfiscal@fvfiscal', 'remove');

        return $head;
}

/**
 * Encrypt value using Dolibarr helper when available.
 *
 * @param string $value
 * @return string
 */
function fvfiscal_encrypt_value($value)
{
        $value = (string) $value;
        if ($value === '') {
                return '';
        }

        if (function_exists('dolEncrypt')) {
                return dolEncrypt($value);
        }
        if (function_exists('dol_encrypt')) {
                return dol_encrypt($value);
        }

        return base64_encode($value);
}

/**
 * Decrypt value previously encoded with {@see fvfiscal_encrypt_value}.
 *
 * @param string $value
 * @return string
 */
function fvfiscal_decrypt_value($value)
{
        $value = (string) $value;
        if ($value === '') {
                return '';
        }

        if (function_exists('dolDecrypt')) {
                $decoded = dolDecrypt($value);
                if ($decoded !== false) {
                        return (string) $decoded;
                }
        }
        if (function_exists('dol_decrypt')) {
                $decoded = dol_decrypt($value);
                if ($decoded !== false) {
                        return (string) $decoded;
                }
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false) {
                return (string) $decoded;
        }

        return $value;
}
