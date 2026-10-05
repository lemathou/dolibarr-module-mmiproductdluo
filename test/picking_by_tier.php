<?php
/**
 *  RM3039 — test PUR du picking contraint par le palier de la ligne.
 *
 *  Ce que ces tests protègent, et pourquoi ils existent :
 *
 *  1. **Jamais moins bien que vendu.** C'est le bug d'origine : Dolibarr triait tous les lots du
 *     produit par DDM croissante et servait le plus proche, donc de la « date courte » à un client
 *     qui avait acheté de la « date longue » — au prix de la date longue. Silencieux côté
 *     boutique : seule l'expédition divergeait. La règle 1 est un invariant, pas une préférence.
 *
 *  2. **Mieux est acceptable, et dans ce sens seulement.** L'asymétrie est volontaire (arbitrage
 *     Mathieu, 2026-10-05) : on complète avec une bande MOINS dégradée pour ne pas bloquer une
 *     expédition, jamais avec une plus dégradée. Quelqu'un lira un jour ce code et voudra
 *     « symétriser » le débordement : ce test doit l'arrêter.
 *
 *  3. **L'ordre suffit à ne pas gâcher.** On ne prend une meilleure bande qu'une fois la bande
 *     vendue épuisée, non par un test explicite mais parce que le tri est à DDM croissante. Si
 *     quelqu'un change ce tri, le gaspillage réapparaît sans message d'erreur.
 *
 *  Aucune base, aucun Dolibarr : les bornes et les lots sont injectés.
 *  Lancer : php7.4 htdocs/custom/mmiproductdluo/test/picking_par_palier.php
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__.'/../class/mmiproductdluo_lotpicker.class.php';

$B = array('promo' => 30, 'antigaspi' => 2, 'perime' => 59, 'perime_allonge' => 90);
$fails = 0;

function check($label, $got, $want)
{
    global $fails;
    $ok = ($got === $want);
    if (!$ok) {
        $fails++;
    }
    printf("%s %s\n", $ok ? '  ok  ' : 'FAIL  ', $label);
    if (!$ok) {
        printf("        attendu : %s\n        obtenu  : %s\n", var_export($want, true), var_export($got, true));
    }
}

/** Fabrique un lot au format `lotsForProduct`. */
function lot($batch, $days, $qty = 10, $dm = 0)
{
    return array(
        'fk_product_lot' => crc32($batch) % 100000,
        'batch' => $batch,
        'days' => $days,
        'qty' => $qty,
        'antigaspi_autoremove_disabled' => $dm,
    );
}

/** Les numéros de lot rendus, dans l'ordre. */
function batches($lots)
{
    return array_map(function ($l) { return $l['batch']; }, $lots);
}

$P = 'MMIProductDluo_LotPicker';

// ---------------------------------------------------------------- rangs des bandes
echo "\n== rangs des bandes ==\n";
check('normal est la meilleure bande', $P::rangPalier($P::P_NORMAL), 3);
check('date courte vient après', $P::rangPalier($P::P_COURTE), 2);
check('antigaspi encore après', $P::rangPalier($P::P_ANTIGASPI), 1);
check('dernière minute est la plus dégradée', $P::rangPalier($P::P_DM), 0);
check('un palier inconnu ne se devine pas', $P::rangPalier('pas_un_palier'), null);
check('un palier absent ne se devine pas', $P::rangPalier(null), null);

// ---------------------------------------------- 1. jamais une bande plus dégradée
echo "\n== l'invariant : jamais moins bien que vendu ==\n";
// +43 j = normal (>30) ; +8 j = date courte (>2, <=30) ; -10 j = antigaspi
$stock = array(lot('LONGUE', 43), lot('COURTE', 8), lot('GASPI', -10));

check(
    'vendu en date longue : la date courte et l\'antigaspi sont EXCLUS',
    batches($P::lotsPourPalier($stock, $P::P_NORMAL, $B)),
    array('LONGUE')
);
check(
    'vendu en date courte : l\'antigaspi est exclu, la date longue reste permise',
    batches($P::lotsPourPalier($stock, $P::P_COURTE, $B)),
    array('COURTE', 'LONGUE')
);
check(
    'vendu en antigaspi : tout ce qui est vendable est permis',
    batches($P::lotsPourPalier($stock, $P::P_ANTIGASPI, $B)),
    array('GASPI', 'COURTE', 'LONGUE')
);

// -------------------------------------------- 2. le débordement est à sens unique
echo "\n== l'asymétrie du débordement ==\n";
// Bande vendue insuffisante : on complète vers le MOINS dégradé, jamais l'inverse.
$peu = array(lot('COURTE-1', 5, 3), lot('LONGUE-1', 60, 50), lot('GASPI-1', -5, 50));
check(
    'date courte en rupture : on complète par la date longue, pas par l\'antigaspi',
    batches($P::lotsPourPalier($peu, $P::P_COURTE, $B)),
    array('COURTE-1', 'LONGUE-1')
);
check(
    'date longue en rupture : rien ne complète, on ne descend JAMAIS d\'une bande',
    batches($P::lotsPourPalier(array(lot('COURTE-1', 5, 99), lot('GASPI-1', -5, 99)), $P::P_NORMAL, $B)),
    array()
);

// ------------------------------------------- 3. l'ordre évite de gâcher le stock
echo "\n== l'ordre : la bande vendue d'abord, DDM la plus proche d'abord ==\n";
$melange = array(lot('L-90', 90), lot('C-3', 3), lot('L-35', 35), lot('C-29', 29));
check(
    'vendu en date courte : les deux lots courts avant les deux longs, et chacun par DDM croissante',
    batches($P::lotsPourPalier($melange, $P::P_COURTE, $B)),
    array('C-3', 'C-29', 'L-35', 'L-90')
);
check(
    'à DDM égale, l\'ordre reste stable (numéro de lot)',
    batches($P::lotsPourPalier(array(lot('B', 10), lot('A', 10)), $P::P_COURTE, $B)),
    array('A', 'B')
);

// ------------------------------------------------- lots non vendables et bornes
echo "\n== lots non vendables ==\n";
check(
    'un lot périmé au-delà de la borne est écarté même sans palier',
    batches($P::lotsPourPalier(array(lot('MORT', -80), lot('COURTE', 8)), null, $B)),
    array('COURTE')
);
check(
    'le même lot est vendable en « dernière minute » SI le drapeau est posé',
    batches($P::lotsPourPalier(array(lot('DM', -80, 10, 1)), $P::P_DM, $B)),
    array('DM')
);
check(
    'un lot sans DDM n\'est jamais servi (on ne devine pas sa bande)',
    batches($P::lotsPourPalier(array(array('batch' => 'SANS', 'days' => null, 'qty' => 5)), null, $B)),
    array()
);

// ---------------------------------------------------- absence de palier = statu quo
echo "\n== ligne sans palier : aucune restriction de bande ==\n";
check(
    'sans palier, tous les lots vendables sont rendus, DDM la plus proche d\'abord',
    batches($P::lotsPourPalier($stock, null, $B)),
    array('GASPI', 'COURTE', 'LONGUE')
);
check(
    'un palier inconnu se comporte comme une absence, il ne bloque pas tout',
    batches($P::lotsPourPalier($stock, 'inconnu', $B)),
    array('GASPI', 'COURTE', 'LONGUE')
);

// -------------------------------------------------------------- cas dégénérés
echo "\n== cas dégénérés ==\n";
check('aucun lot en stock', batches($P::lotsPourPalier(array(), $P::P_NORMAL, $B)), array());
check('entrée non tableau', batches($P::lotsPourPalier(null, $P::P_NORMAL, $B)), array());

printf("\n%s\n", $fails === 0 ? "TOUS LES TESTS PASSENT" : "$fails TEST(S) EN ÉCHEC");
exit($fails === 0 ? 0 : 1);
