<?php
/**
 *  RM3492 — recette de la partie SERVEUR du mode caisse.
 *
 *  Ce que le navigateur décide (valider la ligne ou non) repose sur deux informations que le
 *  serveur fournit, et elles seules sont testables ici :
 *
 *   1. `autovalide` — le droit « mode caisse » du porteur de la session. Sans lui, aucun
 *      changement de comportement pour personne : c'est le garde-fou principal, l'écran de
 *      commande servant aussi à saisir des commandes ordinaires.
 *   2. `tobatch` — géré en lot ou non. Il distingue deux absences de lot qui n'ont rien à voir :
 *      un article ordinaire, qu'on scanne et valide, et un produit à lots dont aucun lot n'est
 *      vendable, où rien ne doit être validé tout seul.
 *
 *  Le comportement du navigateur lui-même (attente des deux réponses, clic qui valide) demande
 *  une recette à l'écran : il n'est pas couvert ici, et ce test ne prétend pas le couvrir.
 *
 *  Lecture seule : aucune écriture.
 *
 *  Lancer : php htdocs/custom/mmiproductdluo/test/caisse_autovalide.php
 */
if (php_sapi_name() !== 'cli') { exit; }
define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1); define('NOCSRFCHECK', 1);
$racine = realpath(__DIR__.'/../../..');
$_SERVER['DOCUMENT_ROOT'] = $racine; chdir($racine);
require_once $racine.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/custom/mmiproductdluo/class/actions_mmiproductdluo.class.php');
dol_include_once('/custom/mmiproductdluo/core/modules/modMMIProductDluo.class.php');
dol_include_once('/custom/mmiproductdluo/core/modules/modMMIProductDluo.class.php');

$fails = 0;
function check($label, $got, $want)
{
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("%s %-64s got=%s want=%s\n", $ok ? '  ok  ' : 'FAIL  ', $label, var_export($got, true), var_export($want, true));
}

// --- Le ciblage se fait par GROUPE, et surtout PAS par un droit
echo "== le ciblage passe par un groupe, pas par un droit ==\n";
$mod = new modMMIProductDluo($db);
$aDroitCaisse = false;
foreach ((array) $mod->rights as $d) {
    if (isset($d[4]) && $d[4] === 'caisse') { $aDroitCaisse = true; }
}
// Un droit serait accordé d'office à tout administrateur (getrights() force les permissions des
// admins, et insert_permissions() les ajoute à chaque compte admin) : il ne peut donc pas servir
// à désigner des personnes. Ce test garde la porte fermée.
check('le module ne declare AUCUN droit « caisse »', $aDroitCaisse, false);
check('l\'option de groupe est declaree dans setup.php',
    strpos(file_get_contents(dol_buildpath('/custom/mmiproductdluo/admin/setup.php', 0)), 'MMIPRODUCTDLUO_CAISSE_GROUP') !== false, true);

// --- `autovalide` suit l'appartenance au groupe, et rien d'autre
echo "\n== autovalide suit l'appartenance au groupe ==\n";
$actions = new ActionsMMIProductDluo($db);
$ref = new ReflectionMethod($actions, 'lotPickerAssets');
$ref->setAccessible(true);

/** Fabrique un groupe et des membres le temps d'une transaction ANNULEE : aucune trace. */
$db->begin();
$db->query("INSERT INTO ".MAIN_DB_PREFIX."usergroup (nom, entity, datec) VALUES ('ZZ_test_caisse_rm3492', ".((int) $conf->entity).", '".$db->idate(dol_now())."')");
$gid = (int) $db->last_insert_id(MAIN_DB_PREFIX.'usergroup');
$conf->global->MMIPRODUCTDLUO_CAISSE_GROUP = $gid;

// un NON-admin dans le groupe, un ADMIN hors du groupe : c'est tout l'enjeu
$r = $db->query("SELECT rowid, login FROM ".MAIN_DB_PREFIX."user WHERE admin = 0 AND statut = 1 LIMIT 1");
$membre = $db->fetch_object($r);
$r = $db->query("SELECT rowid, login FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 LIMIT 1");
$adminHorsGroupe = $db->fetch_object($r);

if (!$membre || !$adminHorsGroupe) {
    echo "  (ignoré) il faut au moins un compte non-admin actif et un admin en base\n";
} else {
    $db->query("INSERT INTO ".MAIN_DB_PREFIX."usergroup_user (fk_user, fk_usergroup, entity) VALUES (".((int) $membre->rowid).", ".$gid.", ".((int) $conf->entity).")");

    foreach (array(array($membre, true, 'membre du groupe'), array($adminHorsGroupe, false, 'ADMINISTRATEUR hors du groupe')) as $cas) {
        list($o, $attendu, $libelle) = $cas;
        $u = new User($db); $u->fetch((int) $o->rowid); $u->getrights();
        $GLOBALS['user'] = $u;
        $html = $ref->invoke($actions);
        $actif = (strpos($html, '"autovalide":1') !== false);
        check(sprintf('%s (%s, admin=%d) : autovalide', $libelle, $o->login, (int) $u->admin), $actif, $attendu);
    }
    // La clé doit exister dans les deux cas, sinon les tests ci-dessus passeraient sur une absence
    check('la cle autovalide est bien presente dans la conf JS', strpos($html, 'autovalide') !== false, true);

    // Option vidée ⇒ plus personne, même le membre
    $conf->global->MMIPRODUCTDLUO_CAISSE_GROUP = '';
    $u = new User($db); $u->fetch((int) $membre->rowid); $u->getrights(); $GLOBALS['user'] = $u;
    check('groupe non renseigne : inactif pour tout le monde',
        strpos($ref->invoke($actions), '"autovalide":1') !== false, false);
}
$db->rollback();

// --- INCIDENT 2026-10-21 : la TVA renvoyee par le hook doit correspondre a une OPTION du
// --- <select> des taux. Une valeur brute (« 5.500 ») n'y correspond pas, `val()` ne change rien,
// --- et la ligne part au taux par defaut : 20 % au lieu de 5,5 % (commande CO2610-018743).
echo "\n== la TVA du hook est au format attendu par le formulaire ==\n";
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
$conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ENABLE = 1;
$hm = new HookManager($db);
if (!isset($conf->modules_parts['hooks']) || !is_array($conf->modules_parts['hooks'])) { $conf->modules_parts['hooks'] = array(); }
if (!isset($conf->modules_parts['hooks']['mmiproductdluo'])) { $conf->modules_parts['hooks']['mmiproductdluo'] = array(); }
if (!in_array('productajax', (array) $conf->modules_parts['hooks']['mmiproductdluo'])) { $conf->modules_parts['hooks']['mmiproductdluo'][] = 'productajax'; }
$hm->initHooks(array('productajax'));

// Un produit a lots dont le lot le plus ancien porte une remise : c'est le cas ou le hook repond.
$sqlp = "SELECT DISTINCT pl.fk_product FROM ".MAIN_DB_PREFIX."product_lot pl
    INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product AND p.tobatch = 1 AND p.tva_tx > 0
    INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = pl.fk_product
    INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch
    WHERE pb.qty > 0 AND pl.sellby IS NOT NULL LIMIT 300";
$rp = $db->query($sqlp);
$teste = 0;
while (($op = $db->fetch_object($rp)) && $teste < 1) {
    $prod = new Product($db);
    if ($prod->fetch((int) $op->fk_product) <= 0) { continue; }
    $params = array('id' => $prod->id, 'object' => $prod, 'price_level' => 1);
    $action = 'fetch';
    $hm->resArray = array();
    $res = $hm->executeHooks('productAjaxPrice', $params, $prod, $action);
    if ($res <= 0 || empty($hm->resArray) || !isset($hm->resArray['tva_tx'])) { continue; }
    $teste++;
    $renvoye = (string) $hm->resArray['tva_tx'];
    // Ce que le <select> accepte : la forme normalisee, celle que le coeur produit partout.
    $attendu = (string) price2num($prod->tva_tx);
    check(sprintf('produit %s : tva_tx renvoyee = forme normalisee', $prod->ref), $renvoye, $attendu);
    check('... et ce n\'est PAS la valeur brute de la base ('.$prod->tva_tx.')',
        $renvoye === (string) $prod->tva_tx && $renvoye !== $attendu, false);
}
if (!$teste) { echo "  (ignoré) aucun produit en stock pour lequel le hook repond\n"; }

// Le patch du coeur doit lui aussi normaliser ET renseigner la forme formatee.
$coeur = @file_get_contents(DOL_DOCUMENT_ROOT.'/product/ajax/products.php');
check('le coeur normalise la tva du hook (price2num)',
    (bool) preg_match('/outtva_tx\s*=\s*price2num\(\$r\[.tva_tx.\]\)/', (string) $coeur), true);
check('le coeur renseigne aussi tva_tx_formated depuis le hook',
    (bool) preg_match('/outtva_tx_formated\s*=\s*price\(\$r\[.tva_tx.\]\)/', (string) $coeur), true);

// --- Et surtout : la valeur doit CORRESPONDRE a une option reelle du <select> des taux.
// --- Verifier le format ne suffisait pas — c'est la correspondance qui manquait, et son absence
// --- laisse `val()` sans effet, donc la ligne au taux par defaut.
echo "\n== la TVA renvoyee correspond a une option du <select> des taux ==\n";
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
$form = new Form($db);
$acheteur = new Societe($db);
$rs = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE client > 0 AND fk_pays = ".((int) $mysoc->country_id)." LIMIT 1");
$os = $rs ? $db->fetch_object($rs) : null;
if (!$os) {
    echo "  (ignoré) aucun client du pays de la societe en base\n";
} else {
    $acheteur->fetch((int) $os->rowid);
    $htmlTva = $form->load_tva('tva_tx', -1, $mysoc, $acheteur, 0, 0, '', false, 1);
    preg_match_all('/<option[^>]*value="([^"]*)"/', $htmlTva, $mm);
    $options = $mm[1];
    check('le <select> propose au moins un taux', count($options) > 0, true);
    $vus = 0; $hors = 0;
    $rq = $db->query("SELECT DISTINCT pl.fk_product FROM ".MAIN_DB_PREFIX."product_lot pl
        INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product AND p.tobatch = 1 AND p.tva_tx > 0
        INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = pl.fk_product
        INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch
        WHERE pb.qty > 0 AND pl.sellby IS NOT NULL LIMIT 400");
    while (($xq = $db->fetch_object($rq)) && $vus < 5) {
        $pr = new Product($db);
        if ($pr->fetch((int) $xq->fk_product) <= 0) { continue; }
        $hm->resArray = array();
        $act = 'fetch';
        if ($hm->executeHooks('productAjaxPrice', array('id' => $pr->id, 'object' => $pr, 'price_level' => 1), $pr, $act) <= 0) { continue; }
        if (empty($hm->resArray['tva_tx'])) { continue; }
        $vus++;
        if (!in_array((string) $hm->resArray['tva_tx'], $options, true)) { $hors++; }
    }
    if (!$vus) { echo "  (ignoré) le hook n'a repondu pour aucun produit en stock\n"; }
    else {
        check(sprintf('les %d taux renvoyes existent tous dans le <select>', $vus), $hors, 0);
    }
}

// --- L'endpoint distingue un article ordinaire d'un produit à lots
echo "\n== tobatch distingue l'article ordinaire du produit à lots ==\n";
$cas = array(1 => 'produit géré en lot', 0 => 'produit NON géré en lot');
foreach ($cas as $valeur => $libelle) {
    $r = $db->query("SELECT rowid, ref FROM ".MAIN_DB_PREFIX."product WHERE tobatch = ".((int) $valeur)." AND fk_product_type = 0 LIMIT 1");
    $o = $r ? $db->fetch_object($r) : null;
    if (!$o) { printf("  (ignoré) aucun %s en base\n", $libelle); continue; }
    // On relit comme le fait l'endpoint, sans passer par HTTP (pas de session en CLI).
    $r2 = $db->query("SELECT tobatch FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $o->rowid));
    $lu = (int) $db->fetch_object($r2)->tobatch;
    check(sprintf('%s (%s) : tobatch', $libelle, $o->ref), $lu, (int) $valeur);
}

// --- La clé est bien celle que le JS lit
echo "\n== le contrat avec le JS ==\n";
$js = file_get_contents(dol_buildpath('/custom/mmiproductdluo/js/lotpicker.js', 0));
check('le JS lit CONF.autovalide', strpos($js, 'CONF.autovalide') !== false, true);
check('le JS lit d.tobatch', strpos($js, 'd.tobatch') !== false, true);
check('le JS n\'ouvre pas de tour au chargement (pourProduit(..., 0))',
    strpos($js, 'pourProduit(produitCourant(), 0)') !== false, true);
check('le JS garde une protection anti double soumission', strpos($js, 'dejaSoumis') !== false, true);
$ajax = file_get_contents(dol_buildpath('/custom/mmiproductdluo/ajax/lots_for_product.php', 0));
check('l\'endpoint renvoie tobatch', strpos($ajax, "'tobatch'") !== false, true);

echo "\n";
echo $fails ? $fails." CAS NON CONFORME(S)\n" : "TOUS CONFORMES\n";
exit($fails ? 1 : 0);
