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
 * \file    mmiproductdluo/ajax/lots_for_product.php
 * \ingroup mmiproductdluo
 * \brief   Endpoint AJAX (RM3040) : les lots vendables d un produit, pour la CAISSE.
 *
 * Appelé à la lecture d'un code-barres sur l'écran de commande : rend les lots en stock
 * du produit, DDM la plus proche d'abord, et la conduite à tenir —
 *   none   : aucun lot (produit non géré en lot, ou stock épuisé) => ne pas bloquer la vente
 *   auto   : un seul lot => à retenir SANS interaction (la caisse ne s'arrête pas)
 *   choose : plusieurs lots => présenter la sélection, le premier étant le meilleur choix
 *
 *  - fk_product=<id> [&fk_entrepot=<id>] (GET) ->
 *      { success:1, mode:'none'|'auto'|'choose', lot:{…}|null, lots:[{batch,sellby,qty,label},…] }
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
dol_include_once('/custom/mmiproductdluo/class/mmiproductdluo_lotpicker.class.php');

// Output JSON
top_httphead('application/json');

// Sécurité : lire les lots d'un produit suppose au minimum le droit de lire les commandes
// (c'est l'écran d'où l'appel part) ou les produits.
if (empty($user->rights->commande->lire) && empty($user->rights->produit->lire)) {
	echo json_encode(array('success' => 0, 'error' => 'Forbidden'));
	exit;
}

$fk_product  = GETPOST('fk_product', 'int');
$fk_entrepot = GETPOST('fk_entrepot', 'int');
if ($fk_product <= 0) {
	echo json_encode(array('success' => 0, 'error' => 'fk_product manquant'));
	exit;
}

$picker = new MMIProductDluo_LotPicker($db);
$lots = $picker->lotsForProduct($fk_product, $fk_entrepot);
$decision = MMIProductDluo_LotPicker::decide($lots);

// libellé prêt à afficher : l'opérateur lit une ligne, pas un objet
foreach ($decision['lots'] as $i => $l) {
	$decision['lots'][$i]['label'] = MMIProductDluo_LotPicker::label($l);
}
if (!empty($decision['lot'])) {
	$decision['lot']['label'] = MMIProductDluo_LotPicker::label($decision['lot']);
}

echo json_encode(array(
	'success' => 1,
	'mode'    => $decision['mode'],
	'lot'     => $decision['lot'],
	'lots'    => $decision['lots'],
));
