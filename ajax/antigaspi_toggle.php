<?php
/* Copyright (C) 2026 Mathieu Moulin <mathieu@iprospective.fr>
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
 * \file    mmiproductdluo/ajax/antigaspi_toggle.php
 * \ingroup mmiproductdluo
 * \brief   Endpoint AJAX : lit / bascule l'extrafield antigaspi_autoremove_disabled d'un lot.
 *
 * Actions :
 *  - action=getstate&fk_product=<id>  (GET)  -> { success:1, states:{ <lotid>:0|1, ... } }
 *  - action=toggle&lotid=<id>&token=… (POST) -> { success:1, lotid:<id>, state:0|1 }
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // Disables token renewal
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';

$action = GETPOST('action', 'aZ09');

// Output JSON
top_httphead('application/json');

// Security : il faut au minimum un droit d'écriture sur les produits ou le stock.
if (empty($user->rights->produit->creer) && empty($user->rights->stock->creer)) {
	echo json_encode(array('success' => 0, 'error' => 'Forbidden'));
	exit;
}

$EXTRAKEY = 'antigaspi_autoremove_disabled';

if ($action == 'getstate') {
	$fk_product = GETPOST('fk_product', 'int');
	$states = array();
	if ($fk_product > 0) {
		$sql = "SELECT pl.rowid, COALESCE(ef.".$EXTRAKEY.", 0) as val";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_lot as pl";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot_extrafields as ef ON ef.fk_object = pl.rowid";
		$sql .= " WHERE pl.fk_product = ".((int) $fk_product);
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$states[(int) $obj->rowid] = (int) $obj->val ? 1 : 0;
			}
		} else {
			echo json_encode(array('success' => 0, 'error' => $db->lasterror()));
			exit;
		}
	}
	echo json_encode(array('success' => 1, 'states' => $states));
	exit;
}

if ($action == 'toggle') {
	$lotid = GETPOST('lotid', 'int');
	if ($lotid <= 0) {
		echo json_encode(array('success' => 0, 'error' => 'Bad lot id'));
		exit;
	}

	$lot = new Productlot($db);
	if ($lot->fetch($lotid) <= 0) {
		echo json_encode(array('success' => 0, 'error' => 'Lot not found'));
		exit;
	}

	$current = !empty($lot->array_options['options_'.$EXTRAKEY]) ? 1 : 0;
	$new = $current ? 0 : 1;
	$lot->array_options['options_'.$EXTRAKEY] = $new;

	$result = $lot->insertExtraFields('', $user);
	if ($result < 0) {
		echo json_encode(array('success' => 0, 'error' => ($lot->error ? $lot->error : 'Update failed')));
		exit;
	}

	echo json_encode(array('success' => 1, 'lotid' => (int) $lotid, 'state' => $new));
	exit;
}

echo json_encode(array('success' => 0, 'error' => 'Unknown action'));
exit;
