<?php
/**
 *  RM3040 — recette : le prix proposé à la saisie suit la BANDE du lot.
 *
 *  Le défaut corrigé : la règle vivait en dur dans `product/ajax/products.php` et n'y
 *  connaissait que deux bandes sur quatre. Un lot dépassé de 71 jours repartait à −25 %
 *  (antigaspi) au lieu de −70 % (dernière minute) — commande 18705, lot 9494.
 *
 *  Ce test appelle le hook du module comme le fait le cœur, sur de VRAIS lots de la base, et
 *  vérifie que chaque bande reçoit sa remise. Lecture seule : aucune écriture.
 *
 *  Lancer : php7.4 htdocs/custom/mmiproductdluo/test/prix_par_bande.php
 */
if (php_sapi_name() !== 'cli') { exit; }
define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1); define('NOCSRFCHECK', 1);
$racine = realpath(__DIR__.'/../../..');
$_SERVER['DOCUMENT_ROOT'] = $racine; chdir($racine);
require_once $racine.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/custom/mmiproductdluo/class/actions_mmiproductdluo.class.php');
dol_include_once('/custom/mmiproductdluo/class/mmiproductdluo_lotpicker.class.php');

$fails = 0;
function check($label, $got, $want)
{
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("%s %-62s got=%s want=%s\n", $ok ? '  ok  ' : 'FAIL  ', $label, var_export($got, true), var_export($want, true));
}

// La règle ne s'applique que si l'option est active : on la force pour le test, en mémoire.
$conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ENABLE = 1;

$P = 'MMIProductDluo_LotPicker';
$bornes = $P::bornes();
printf("bornes : %s\n", json_encode($bornes));
$attendu = array(
    $P::P_NORMAL    => null,   // pas de remise : le hook ne répond pas
    $P::P_COURTE    => (float) (isset($conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_PROMO) && $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_PROMO !== '' ? $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_PROMO : 10),
    $P::P_ANTIGASPI => (float) (isset($conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ANTIGASPI) && $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ANTIGASPI !== '' ? $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ANTIGASPI : 25),
    $P::P_DM        => (float) (isset($conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_DM) && $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_DM !== '' ? $conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_DM : 70),
);
printf("remises attendues : %s\n\n", json_encode($attendu));

$picker = new MMIProductDluo_LotPicker($db);
$actions = new ActionsMMIProductDluo($db);

/** Cherche un produit dont le PREMIER lot (DDM la plus proche) tombe dans la bande voulue. */
function produitPourBande($db, $picker, $bande, $bornes)
{
    $sql = "SELECT DISTINCT pl.fk_product FROM ".MAIN_DB_PREFIX."product_lot pl
        INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product AND p.tobatch = 1 AND p.fk_product_type = 0
        INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = pl.fk_product
        INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch
        WHERE pb.qty > 0 AND pl.sellby IS NOT NULL LIMIT 400";
    $res = $db->query($sql);
    while ($o = $db->fetch_object($res)) {
        $lots = $picker->lotsForProduct((int) $o->fk_product);
        if (empty($lots)) { continue; }
        if (MMIProductDluo_LotPicker::palierDuLot($lots[0], $bornes) === $bande) {
            return array((int) $o->fk_product, $lots[0]);
        }
    }
    return array(0, null);
}

foreach ($attendu as $bande => $remiseAttendue) {
    list($fk_product, $lot) = produitPourBande($db, $picker, $bande, $bornes);
    if (!$fk_product) {
        printf("  (ignoré) aucune donnée en stock pour la bande « %s »\n", $bande);
        continue;
    }
    $produit = new Product($db);
    $produit->fetch($fk_product);
    $hm = new HookManager($db);
    $params = array('id' => $fk_product, 'object' => $produit, 'price_level' => 0);
    $action = '';
    $r = $actions->productAjaxPrice($params, $produit, $action, $hm);
    $remise = ($r > 0 && isset($hm->resArray['discount'])) ? (float) $hm->resArray['discount'] : null;
    check(sprintf('bande %-14s (produit %d, lot %s, %s j)', $bande, $fk_product, $lot['batch'], $lot['days']),
        $remise, $remiseAttendue);
}

// --- La bande « dernière minute » manque souvent en stock, et c'est précisément celle que le
// --- défaut touchait. On la FORGE : un lot déjà bien périmé reçoit le drapeau le temps d'une
// --- transaction ANNULÉE. Sans ce cas, le test passerait sans rien prouver de l'essentiel.
echo "\n== bande « dernière minute » forgée (transaction annulée) ==\n";
$sql = "SELECT pl.rowid, pl.fk_product, pl.batch, DATEDIFF(pl.sellby, NOW()) AS days
    FROM ".MAIN_DB_PREFIX."product_lot pl
    INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product AND p.tobatch = 1 AND p.fk_product_type = 0
    INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = pl.fk_product
    INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch
    WHERE pb.qty > 0 AND DATEDIFF(pl.sellby, NOW()) BETWEEN -".((int) $bornes['perime_allonge'])." AND -".((int) $bornes['perime'] + 1)."
    ORDER BY pl.rowid LIMIT 1";
$o = $db->fetch_object($db->query($sql));
if (!$o) {
    echo "  (ignoré) aucun lot dont la DDM tombe dans la fenêtre « dernière minute »\n";
} else {
    $db->begin();
    $db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."product_lot_extrafields (fk_object) VALUES (".(int) $o->rowid.")");
    $db->query("UPDATE ".MAIN_DB_PREFIX."product_lot_extrafields SET antigaspi_autoremove_disabled = 1 WHERE fk_object = ".(int) $o->rowid);

    $picker2 = new MMIProductDluo_LotPicker($db);
    $lots2 = $picker2->lotsForProduct((int) $o->fk_product);
    $bande2 = !empty($lots2) ? MMIProductDluo_LotPicker::palierDuLot($lots2[0], $bornes) : null;
    check(sprintf('le lot %s (%s j) est classé en dernière minute', $o->batch, $o->days), $bande2, $P::P_DM);

    $produit2 = new Product($db); $produit2->fetch((int) $o->fk_product);
    $hm2 = new HookManager($db);
    $params2 = array('id' => (int) $o->fk_product, 'object' => $produit2, 'price_level' => 0);
    $action2 = '';
    $r2 = $actions->productAjaxPrice($params2, $produit2, $action2, $hm2);
    $remise2 = ($r2 > 0 && isset($hm2->resArray['discount'])) ? (float) $hm2->resArray['discount'] : null;
    check('... et reçoit la remise « dernière minute », pas celle d\'antigaspi', $remise2, $attendu[$P::P_DM]);
    check('... ce qui n\'est PAS la remise antigaspi (le défaut d\'origine)', $remise2 === $attendu[$P::P_ANTIGASPI], false);

    $db->rollback();
}

// --- RM3040 : la table des remises est UNIQUE. Le hook (prix à la saisie), l'endpoint AJAX
// --- (prix reposé quand l'opérateur change de lot) et toute lecture future doivent rendre la
// --- même valeur pour une bande donnée. Elle a déjà existé en trois exemplaires divergents.
echo "\n== une seule table de remises, lue par tous ==\n";
foreach ($attendu as $bande => $remiseAttendue) {
    $v = $P::discountForTier($bande);
    check(sprintf('discountForTier(%s)', $bande), $v, $remiseAttendue === null ? 0.0 : (float) $remiseAttendue);
}
check('une bande inconnue ne donne aucune remise', $P::discountForTier('zzz'), 0.0);
check('aucune bande (null) ne donne aucune remise', $P::discountForTier(null), 0.0);

echo "\n== l'endpoint expose la remise de CHAQUE lot, pas celle du plus ancien ==\n";
// Un produit à deux bandes : c'est le cas où l'ancien comportement se trompait.
list($fk3, $l3) = produitPourBande($db, $picker, $P::P_ANTIGASPI, $bornes);
if (!$fk3) {
    echo "  (ignoré) aucun produit avec un premier lot en antigaspi\n";
} else {
    $tous = $picker->lotsForProduct($fk3);
    $bandes = array();
    foreach ($tous as $l) {
        $b = $P::palierDuLot($l, $bornes);
        if ($b !== null) { $bandes[$b] = $P::discountForTier($b); }
    }
    printf("  produit %d : %s\n", $fk3, json_encode($bandes));
    check('chaque bande du produit a SA remise, distincte', count(array_unique($bandes)) === count($bandes) || count($bandes) === 1, true);
}

printf("\n%s\n", $fails === 0 ? "CONFORME" : "$fails CAS NON CONFORME(S)");
exit($fails === 0 ? 0 : 1);
