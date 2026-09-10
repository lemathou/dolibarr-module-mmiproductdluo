<?php
/**
 *  RM3040 — test CLI de la DÉCISION DE CAISSE (MMIProductDluo_LotPicker).
 *
 *  Ce qui est protégé : l'écran de commande sert de caisse, avec une douchette. La règle
 *  qui compte n'est pas « proposer les lots », c'est **ne pas s'arrêter quand il n'y a rien
 *  à décider**. Un opérateur qui doit valider une liste à un seul élément à chaque article,
 *  c'est une caisse inutilisable.
 *
 *    · aucun lot     → on ne bloque pas la vente (produit non géré en lot, ou stock épuisé) ;
 *    · un seul lot   → retenu d'office, sans interaction ;
 *    · plusieurs     → sélection présentée, DDM la plus proche EN TÊTE (c'est ce qu'on écoule).
 *
 *  La décision est pure : aucun accès base, donc testable ici sans Dolibarr.
 *
 *  Lancer : php7.4 htdocs/custom/mmiproductdluo/test/lotpicker_decide.php
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__.'/../class/mmiproductdluo_lotpicker.class.php';

$fails = 0;
function check($label, $cond, $detail = '')
{
    global $fails;
    echo ($cond ? "ok   " : "FAIL ").$label.($cond ? '' : ' — '.$detail)."\n";
    if (!$cond) {
        $fails++;
    }
}

$lotA = array('batch' => 'L-PROCHE', 'sellby' => '2026-09-20', 'eatby' => null, 'qty' => 4, 'days' => 10,
              'antigaspi_autoremove_disabled' => 0);
$lotB = array('batch' => 'L-LOIN', 'sellby' => '2026-12-31', 'eatby' => null, 'qty' => 12, 'days' => 112,
              'antigaspi_autoremove_disabled' => 0);

// ── aucun lot : la caisse continue ───────────────────────────────────────────
$d = MMIProductDluo_LotPicker::decide(array());
check('aucun lot => mode "none" (la vente n\'est pas bloquée)', $d['mode'] === 'none', $d['mode']);
check('aucun lot => aucun lot retenu', $d['lot'] === null);
$d = MMIProductDluo_LotPicker::decide(null);
check('entrée nulle tolérée', $d['mode'] === 'none');

// ── un seul lot : zéro interaction ───────────────────────────────────────────
$d = MMIProductDluo_LotPicker::decide(array($lotA));
check('un seul lot => mode "auto"', $d['mode'] === 'auto', $d['mode']);
check('…et c\'est CE lot qui est retenu', $d['lot']['batch'] === 'L-PROCHE');

// ── plusieurs lots : on demande, sans réordonner ─────────────────────────────
$d = MMIProductDluo_LotPicker::decide(array($lotA, $lotB));
check('plusieurs lots => mode "choose"', $d['mode'] === 'choose', $d['mode']);
check('…aucun lot retenu d\'office', $d['lot'] === null);
check('…l\'ordre reçu est PRÉSERVÉ (la requête a déjà trié : DDM la plus proche en tête)',
    $d['lots'][0]['batch'] === 'L-PROCHE' && $d['lots'][1]['batch'] === 'L-LOIN');
check('…les deux lots sont proposés', count($d['lots']) === 2);

// une liste indexée n'importe comment ne doit pas casser la décision
$d = MMIProductDluo_LotPicker::decide(array(7 => $lotA, 3 => $lotB));
check('clés non séquentielles tolérées (array_values)', $d['mode'] === 'choose' && $d['lots'][0]['batch'] === 'L-PROCHE');

// ── libellé opérateur : lisible d'un coup d'œil ──────────────────────────────
$l = MMIProductDluo_LotPicker::label($lotA);
check('libellé : batch, DDM en clair, stock', strpos($l, 'L-PROCHE') === 0 && strpos($l, '20/09/2026') !== false && strpos($l, '4 en stock') !== false, $l);
check('lot sans DDM : dit « sans DDM » plutôt que d\'inventer une date',
    strpos(MMIProductDluo_LotPicker::label(array('batch' => 'X', 'sellby' => '0000-00-00', 'qty' => 1)), 'sans DDM') !== false);
check('quantité entière affichée sans décimale',
    strpos(MMIProductDluo_LotPicker::label(array('batch' => 'X', 'sellby' => '', 'qty' => 3.0)), '3 en stock') !== false);

// ── valeur stockée sur la ligne ──────────────────────────────────────────────
check('la ligne porte le BATCH', MMIProductDluo_LotPicker::toFieldValue($lotA) === 'L-PROCHE');
check('lot vide => chaîne vide, jamais null', MMIProductDluo_LotPicker::toFieldValue(array()) === '');

echo "\n";
if ($fails) {
    echo "ÉCHEC ($fails)\n";
    exit(1);
}
echo "OK\n";
