<?php
/**
 *  RM3040 — test PUR du palier et de la VALEUR PROPOSÉE PAR DÉFAUT à la saisie d'une ligne.
 *
 *  Deux règles, deux raisons d'être protégées :
 *
 *  1. Le PALIER doit se lire ici exactement comme en boutique. C'est la même remise consentie
 *     au même client : si l'ERP et la boutique classent différemment la même DDM, le prix
 *     affiché et le prix facturé divergent sans que rien ne le signale. Les bornes et leur
 *     INCLUSION sont donc verrouillées une par une (+30 est déjà en courte ; −59 encore en
 *     antigaspi ; « dernière minute » exige le drapeau, sinon le lot sort de la vente).
 *
 *  2. Le DÉFAUT a deux temps, et le sens s'INVERSE entre les deux (arbitrage Mathieu,
 *     2026-09-29) : on écoule d'abord la DDM la plus proche parmi « longue » et « courte » ;
 *     mais dès qu'on est contraint de puiser dans l'antigaspi, on sert le lot le MOINS dégradé,
 *     donc la DDM la plus LOINTAINE. C'est contre-intuitif à la lecture rapide — sans ce test,
 *     quelqu'un « corrigera » un jour ce qu'il prendra pour une erreur.
 *
 *  Aucune base, aucun Dolibarr : les bornes sont injectées.
 *  Lancer : php7.4 htdocs/custom/mmiproductdluo/test/palier_et_defaut.php
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
    echo ($ok ? 'ok   ' : 'FAIL ').$label.($ok ? '' : ' — attendu '.var_export($want, true).', obtenu '.var_export($got, true))."\n";
    if (!$ok) {
        $fails++;
    }
}
$P = 'MMIProductDluo_LotPicker';
$lot = function ($batch, $days, $auto = 0) {
    return array('batch' => $batch, 'days' => $days, 'sellby' => '', 'qty' => 5,
                 'antigaspi_autoremove_disabled' => $auto);
};
$pal = function ($o, $auto = 0) use ($B, $P) {
    return call_user_func(array($P, 'palierPourOffset'), $o, $auto, $B);
};

// ── 1. bornes du palier, à l'identique de la boutique ───────────────────────
check('+365 => date longue', $pal(365), MMIProductDluo_LotPicker::P_NORMAL);
check('+31 => date longue', $pal(31), MMIProductDluo_LotPicker::P_NORMAL);
check('+30 (LA borne) => date courte', $pal(30), MMIProductDluo_LotPicker::P_COURTE);
check('+3 => date courte', $pal(3), MMIProductDluo_LotPicker::P_COURTE);
check('+2 (borne) => antigaspi', $pal(2), MMIProductDluo_LotPicker::P_ANTIGASPI);
check('jour J => antigaspi', $pal(0), MMIProductDluo_LotPicker::P_ANTIGASPI);
check('-59 (borne incluse) => antigaspi', $pal(-59), MMIProductDluo_LotPicker::P_ANTIGASPI);
check('-60 sans drapeau => NON VENDABLE', $pal(-60), null);
check('-60 avec drapeau => dernière minute', $pal(-60, 1), MMIProductDluo_LotPicker::P_DM);
check('-90 avec drapeau => dernière minute', $pal(-90, 1), MMIProductDluo_LotPicker::P_DM);
check('-91 même avec drapeau => NON VENDABLE', $pal(-91, 1), null);

// ── 2. palier d'un lot rendu par lotsForProduct ─────────────────────────────
check('lot sans DDM (days null) => pas de palier deviné',
    MMIProductDluo_LotPicker::palierDuLot($lot('X', null), $B), null);
check('lot à +10 => date courte',
    MMIProductDluo_LotPicker::palierDuLot($lot('X', 10), $B), MMIProductDluo_LotPicker::P_COURTE);

// ── 3. défaut, 1er temps : la plus PROCHE parmi longue et courte ────────────
$lots = array($lot('COURTE', 10), $lot('LONGUE', 200), $lot('ANTI', -5));
$d = MMIProductDluo_LotPicker::choixParDefaut($lots, $B);
check('propose la DDM la plus proche des bandes longue/courte', $d['batch'], 'COURTE');

$lots = array($lot('LONGUE-A', 200), $lot('LONGUE-B', 40));
check('entre deux dates longues, la plus proche',
    MMIProductDluo_LotPicker::choixParDefaut($lots, $B)['batch'], 'LONGUE-B');

check('un antigaspi présent ne passe jamais devant une bande préférée',
    MMIProductDluo_LotPicker::choixParDefaut(array($lot('ANTI', 0), $lot('LONGUE', 90)), $B)['batch'], 'LONGUE');

// ── 4. défaut, 2e temps : le sens s'inverse ─────────────────────────────────
$lots = array($lot('ANTI-VIEUX', -50), $lot('ANTI-RECENT', -1));
$d = MMIProductDluo_LotPicker::choixParDefaut($lots, $B);
check('plus rien en longue/courte => le MOINS dégradé (DDM la plus lointaine)', $d['batch'], 'ANTI-RECENT');

$lots = array($lot('DM', -70, 1), $lot('ANTI', -30));
check('antigaspi préféré à dernière minute (il est le moins dégradé)',
    MMIProductDluo_LotPicker::choixParDefaut($lots, $B)['batch'], 'ANTI');

check('un lot NON VENDABLE n\'est jamais proposé',
    MMIProductDluo_LotPicker::choixParDefaut(array($lot('PERIME', -200)), $B), null);
check('aucun lot => aucune proposition',
    MMIProductDluo_LotPicker::choixParDefaut(array(), $B), null);

// ── 5. mode « date longue uniquement » ──────────────────────────────────────
$lots = array($lot('COURTE', 10), $lot('LONGUE', 200));
check('mode longue seule : la courte n\'est plus proposée',
    MMIProductDluo_LotPicker::choixParDefaut($lots, $B, MMIProductDluo_LotPicker::DEF_LONGUE)['batch'], 'LONGUE');
check('mode longue seule : sans date longue, on bascule au 2e temps',
    MMIProductDluo_LotPicker::choixParDefaut(array($lot('COURTE-A', 5), $lot('COURTE-B', 25)), $B,
        MMIProductDluo_LotPicker::DEF_LONGUE)['batch'], 'COURTE-B');

// ── 6. les clés de palier sont celles de la boutique ────────────────────────
check('clé « normal »', MMIProductDluo_LotPicker::P_NORMAL, 'normal');
check('clé « date_courte »', MMIProductDluo_LotPicker::P_COURTE, 'date_courte');
check('clé « antigaspi »', MMIProductDluo_LotPicker::P_ANTIGASPI, 'antigaspi');
check('clé « antigaspi_dm »', MMIProductDluo_LotPicker::P_DM, 'antigaspi_dm');

echo "\n";
if ($fails) {
    echo "ÉCHEC ($fails)\n";
    exit(1);
}
echo "OK\n";
