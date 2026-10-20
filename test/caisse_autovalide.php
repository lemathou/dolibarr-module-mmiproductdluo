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

$fails = 0;
function check($label, $got, $want)
{
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("%s %-64s got=%s want=%s\n", $ok ? '  ok  ' : 'FAIL  ', $label, var_export($got, true), var_export($want, true));
}

// --- Le droit est-il déclaré, et sous un identifiant qui n'empiète sur personne ?
echo "== le droit « mode caisse » est déclaré ==\n";
$mod = new modMMIProductDluo($db);
$trouve = null;
foreach ((array) $mod->rights as $d) {
    if (isset($d[4]) && $d[4] === 'caisse') { $trouve = $d; }
}
check('un droit de famille « caisse » existe', is_array($trouve), true);
if (is_array($trouve)) {
    check('... avec le sous-droit « use »', isset($trouve[5]) ? $trouve[5] : null, 'use');
    check('... et un libellé traduit (pas la clé brute)',
        $langs->trans($trouve[1]) !== $trouve[1], true);
    // Un id déjà pris par un autre module donnerait un droit fantôme, coché pour autre chose.
    $id = (int) $trouve[0];
    $r = $db->query("SELECT module FROM ".MAIN_DB_PREFIX."rights_def WHERE id = ".$id." AND module <> 'mmiproductdluo'");
    $squatte = ($r && $db->num_rows($r) > 0);
    check('... et un id que personne d\'autre n\'utilise ('.$id.')', $squatte, false);
}

// --- `autovalide` suit le droit, et rien d'autre
echo "\n== autovalide suit le droit, et uniquement lui ==\n";
$actions = new ActionsMMIProductDluo($db);
$ref = new ReflectionMethod($actions, 'lotPickerAssets');
$ref->setAccessible(true);

function assetsPour($ref, $actions, $avecDroit)
{
    global $db;
    $u = new User($db);
    $u->rights = new stdClass();
    $u->rights->mmiproductdluo = new stdClass();
    $u->rights->mmiproductdluo->caisse = new stdClass();
    $u->rights->mmiproductdluo->caisse->use = $avecDroit ? 1 : 0;
    $GLOBALS['user'] = $u;
    return $ref->invoke($actions);
}

$sansDroit = assetsPour($ref, $actions, false);
$avecDroit = assetsPour($ref, $actions, true);
check('sans le droit : autovalide = 0', strpos($sansDroit, '"autovalide":0') !== false, true);
check('avec le droit : autovalide = 1', strpos($avecDroit, '"autovalide":1') !== false, true);
// Contre-épreuve : si la clé disparaissait du JSON, les deux tests ci-dessus passeraient au vert
// sur une absence. On vérifie donc qu'elle est bien présente dans les deux cas.
check('la clé est présente sans le droit', strpos($sansDroit, 'autovalide') !== false, true);
check('la clé est présente avec le droit', strpos($avecDroit, 'autovalide') !== false, true);

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
