<?php
/**
 *  RM3039 — recette d'INTÉGRATION du picking contraint par le palier.
 *
 *  Les tests purs (`picking_by_tier.php`) vérifient la règle ; celui-ci vérifie qu'elle est
 *  bien APPLIQUÉE par `mmi_workflow::order_1clic_shipping()`, c'est-à-dire sur le chemin
 *  réel : vraie commande, vrais lots en stock, vraie écriture de `expeditiondet_batch`.
 *
 *  Ce qu'il apporte et que rien d'autre ne donne : la CONTRE-ÉPREUVE. Le même produit, la
 *  même quantité, sans palier sur la ligne, doit servir un AUTRE lot — celui que l'ancien
 *  FIFO toutes bandes confondues choisissait. Sans cette seconde moitié, un test qui passe
 *  ne prouve rien : il pourrait passer parce que le bon lot est aussi le premier du FIFO.
 *
 *  Sans danger pour les données : chaque cas s'exécute dans une TRANSACTION ANNULÉE, et
 *  l'expédition est créée sans validation, donc aucun mouvement de stock n'a lieu.
 *  À lancer sur un env de DEV, jamais en prod.
 *
 *  Usage : php7.4 htdocs/custom/mmiproductdluo/test/picking_integration.php [id_produit]
 *          (sans argument : un produit ayant du stock dans deux bandes est cherché)
 */
if (php_sapi_name() !== 'cli') { exit(1); }
define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1); define('NOCSRFCHECK', 1);
$racine = realpath(__DIR__.'/../../..');        // test → module → custom → htdocs
$_SERVER['DOCUMENT_ROOT'] = $racine;
chdir($racine);
require_once $racine.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
dol_include_once('/custom/mmiworkflow/class/mmi_workflow.class.php');
dol_include_once('/custom/mmiproductdluo/class/mmiproductdluo_lotpicker.class.php');

$fails = 0;
$limits = MMIProductDluo_LotPicker::bornes();
$picker = new MMIProductDluo_LotPicker($db);

/** Les bandes présentes en stock pour un produit, avec leur quantité. */
function tiers_en_stock($lots, $limits)
{
    $t = array();
    foreach ($lots as $l) {
        $tier = MMIProductDluo_LotPicker::palierDuLot($l, $limits);
        if ($tier === null) { continue; }
        if (!isset($t[$tier])) { $t[$tier] = 0; }
        $t[$tier] += (float) $l['qty'];
    }
    return $t;
}

// --- produit de test : celui passé en argument, sinon un produit à deux bandes ---
$fk_product = isset($argv[1]) ? (int) $argv[1] : 0;
if (!$fk_product) {
    $sql = "SELECT pl.fk_product FROM ".MAIN_DB_PREFIX."product_lot pl"
        ." INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pl.fk_product"
        ." INNER JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = pl.fk_product"
        ." INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch"
        ." WHERE pb.qty > 0 AND pl.sellby IS NOT NULL AND p.tobatch = 1 AND p.fk_product_type = 0"
        ." GROUP BY pl.fk_product HAVING COUNT(DISTINCT pl.batch) >= 2 LIMIT 40";
    $q = $db->query($sql);
    while ($o = $db->fetch_object($q)) {
        if (count(tiers_en_stock($picker->lotsForProduct((int) $o->fk_product), $limits)) >= 2) {
            $fk_product = (int) $o->fk_product;
            break;
        }
    }
}
if (!$fk_product) {
    echo "IGNORÉ : aucun produit n'a de stock dans deux bandes distinctes sur cet env.\n";
    exit(0);
}

$lots = $picker->lotsForProduct($fk_product);
printf("produit %d — bandes en stock : %s\n", $fk_product, json_encode(tiers_en_stock($lots, $limits)));
foreach ($lots as $l) {
    printf("   %-14s days=%-6s qty=%-6s bande=%s\n", $l['batch'], $l['days'], $l['qty'],
        MMIProductDluo_LotPicker::palierDuLot($l, $limits));
}

// --- utilisateur et tiers réels ---
$user = new User($db);
$o = $db->fetch_object($db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1"));
$user->fetch($o->rowid); $user->getrights();
$soc = $db->fetch_object($db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE status = 1 ORDER BY rowid LIMIT 1"));

/**
 * Crée une commande validée d'un produit, pose palier/lot sur la ligne, lance le picking,
 * rend les lots effectivement servis. TOUT est annulé en sortie.
 */
function piquer($qty, $tier, $fk_lot, $db, $user, $soc, $fk_product)
{
    $db->begin();
    $order = new Commande($db);
    $order->socid = (int) $soc->rowid;
    $order->date_commande = dol_now();
    $order->date = dol_now();
    if ($order->create($user) <= 0 || $order->addline('test RM3039', 10, $qty, 0, 0, 0, $fk_product) <= 0) {
        $db->rollback();
        return null;
    }
    // order_1clic_shipping() ne traite que les commandes VALIDÉES
    if ($order->valid($user) <= 0) { $db->rollback(); return null; }
    $order->fetch($order->id); $order->fetch_lines();
    $line = $order->lines[0];

    $sets = array();
    if ($tier !== null)   { $sets[] = "palier = '".$db->escape($tier)."'"; }
    if ($fk_lot !== null) { $sets[] = "fk_product_lot = ".(int) $fk_lot; }
    if ($sets) {
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."commandedet_extrafields (fk_object) VALUES (".(int) $line->id.")");
        $db->query("UPDATE ".MAIN_DB_PREFIX."commandedet_extrafields SET ".implode(', ', $sets)." WHERE fk_object = ".(int) $line->id);
    }
    $order->fetch($order->id); $order->fetch_lines();

    $shipment = mmi_workflow::order_1clic_shipping($user, $order, false);   // sans validation : pas de mouvement de stock

    $servis = array();
    if (!empty($shipment) && !empty($shipment->id)) {
        $q = $db->query("SELECT edb.batch, edb.qty FROM ".MAIN_DB_PREFIX."expeditiondet ed"
            ." INNER JOIN ".MAIN_DB_PREFIX."expeditiondet_batch edb ON edb.fk_expeditiondet = ed.rowid"
            ." WHERE ed.fk_expedition = ".(int) $shipment->id);
        while ($o = $db->fetch_object($q)) { $servis[$o->batch] = (float) $o->qty; }
    }
    $db->rollback();
    return $servis;
}

function check($label, $got, $want)
{
    global $fails;
    $ok = ($got === $want);
    if (!$ok) { $fails++; }
    printf("%s %s\n", $ok ? '  ok  ' : 'FAIL  ', $label);
    printf("        servi   : %s\n", json_encode($got));
    if (!$ok) { printf("        attendu : %s\n", json_encode($want)); }
}

// La bande la MOINS dégradée présente en stock sert de palier vendu : c'est le cas où le
// FIFO global se trompe le plus visiblement.
$tiers = tiers_en_stock($lots, $limits);
$ordre = array(MMIProductDluo_LotPicker::P_NORMAL, MMIProductDluo_LotPicker::P_COURTE,
               MMIProductDluo_LotPicker::P_ANTIGASPI, MMIProductDluo_LotPicker::P_DM);
$vendu = null;
foreach ($ordre as $t) { if (isset($tiers[$t])) { $vendu = $t; break; } }

$attendus = MMIProductDluo_LotPicker::lotsForTier($lots, $vendu, $limits);
$attendu_contraint = $attendus[0]['batch'];
$libres = MMIProductDluo_LotPicker::lotsForTier($lots, null, $limits);
$attendu_fifo = $libres[0]['batch'];

echo "\n== 1. la règle est APPLIQUÉE sur le chemin réel ==\n";
check("palier « $vendu », 1 u. -> lot $attendu_contraint",
      piquer(1, $vendu, null, $db, $user, $soc, $fk_product), array($attendu_contraint => 1.0));

echo "\n== 2. CONTRE-ÉPREUVE : sans palier, le FIFO global choisit un autre lot ==\n";
if ($attendu_fifo === $attendu_contraint) {
    echo "  (ignoré : sur cet env le lot FIFO est aussi le lot de la bande vendue —\n";
    echo "   la contre-épreuve ne distinguerait rien. Relancer sur un produit dont la\n";
    echo "   bande la moins dégradée n'a pas la DDM la plus proche.)\n";
} else {
    check("aucun palier, 1 u. -> lot $attendu_fifo (DDM la plus proche, toutes bandes)",
          piquer(1, null, null, $db, $user, $soc, $fk_product), array($attendu_fifo => 1.0));
}

echo "\n== 3. REFUS plutôt qu'une bande plus dégradée ==\n";
$trop = (int) $tiers[$vendu] + 1;
foreach ($ordre as $t) { if ($t !== $vendu && isset($tiers[$t])) { $trop += (int) $tiers[$t]; } }
check("palier « $vendu », $trop u. (au-delà de tout le stock servable) -> aucune expédition",
      piquer($trop, $vendu, null, $db, $user, $soc, $fk_product), array());

echo "\n== 4. LOT DÉSIGNÉ sur la ligne (caisse) ==\n";
$autre = null;
foreach ($attendus as $l) { if ($l['batch'] !== $attendu_contraint) { $autre = $l; break; } }
if ($autre === null) {
    echo "  (ignoré : une seule option dans la bande vendue, le lot désigné ne se distingue pas)\n";
} else {
    check("lot ".$autre['batch']." désigné -> ce lot, pas $attendu_contraint",
          piquer(1, $vendu, (int) $autre['fk_product_lot'], $db, $user, $soc, $fk_product),
          array($autre['batch'] => 1.0));
}

printf("\n%s\n", $fails === 0 ? "RECETTE D'INTÉGRATION : CONFORME" : "RECETTE D'INTÉGRATION : $fails CAS NON CONFORME(S)");
exit($fails === 0 ? 0 : 1);
