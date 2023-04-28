<?php

dol_include_once('custom/mmicommon/class/mmi_delayed.class.php');
// @todo if conf active mmiprestasync
dol_include_once('/custom/mmiprestasync/class/mmi_prestasync.class.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';

/**
 * Décale l'execution à la mort de l'objet => à la fin du script puisque singleton
 */
class MMIProductDluo_Stock extends MMI_Delayed_1_0
{

// CLASS

/**
 * @var MMIProductDluo_Stock $_instance
 */
protected static $_instance;

// OBJECT

public function execute_value($value)
{
	$this->stock_calculate($value);
}

public function lot_ddm_old_remove()
{
	global $conf, $user, $langs;

	$langs->loadLangs(array("admin", "mmiproductdluo@mmiproductdluo"));

	$time = time();
	$date = date('Y-m-d', $time);
	$datediff = !empty($conf->global->MMIPRODUCTDLUO_PERIME) ?$conf->global->MMIPRODUCTDLUO_PERIME :60;
	$email_from = $conf->global->MMIPRODUCTDLUO_ANTIGASPI_EMAIL_FROM;
	$email_to = $conf->global->MMIPRODUCTDLUO_ANTIGASPI_EMAIL_TO;
	$email_subject = !empty($conf->global->MMIPRODUCTDLUO_ANTIGASPI_EMAIL_SUBJECT) ?$conf->global->MMIPRODUCTDLUO_ANTIGASPI_EMAIL_SUBJECT :'ANTI-GASPI : sortie automatique de stock';

	$list = [
		0=>[], // Sorties effectives
		1=>[], // Stock réservé
		2=>[], // Erreur de sortie
	];

	$reserved = [];

	// Ordonner les stocks pour sortir d'abord ce qui est dans le dépôt, lorsqu'on a un stock réservé pour des commandes pas expédiées
	$sql = 'SELECT p.ref, pl.fk_product, pl.batch, pl.sellby, UNIX_TIMESTAMP(pl.sellby) AS sellby_ts, pb.fk_product_stock, s.fk_entrepot, pb.qty, DATEDIFF(pl.`sellby`, NOW()) AS `datediff`, p.pmp, p.cost_price
		FROM `'.MAIN_DB_PREFIX.'product_lot` pl
		INNER JOIN `'.MAIN_DB_PREFIX.'product` p ON p.rowid=pl.fk_product
		INNER JOIN `'.MAIN_DB_PREFIX.'product_stock` s ON s.fk_product=pl.fk_product
		INNER JOIN `'.MAIN_DB_PREFIX.'product_batch` pb ON pb.fk_product_stock=s.rowid AND pb.batch=pl.batch
		WHERE DATEDIFF(pl.`sellby`, NOW()) < -'.$datediff.' AND pb.qty>0
		ORDER BY pl.fk_product, pl.batch, s.rowid';
	//return $sql;
	//echo $sql;
	$q = $this->db->query($sql);
	foreach($q as $r) {
		// Check
		if (!$r['ref'] || substr($r['ref'], 0, 5)=='TEST-' || substr($r['batch'], 0, 5)=='TEST-')
			continue;
		
		//return $r;
		$product = new Product($this->db);
		$product->fetch($r['fk_product']);
		
		// Stock réservé en dehors des kits, pour lesquels on tape dans le stock forcément en ddm non dépassée, puis même pas en antigaspi
		if (!isset($reserved[$r['fk_product']]))
			$reserved[$r['fk_product']] = $this->reserved_qty($r['fk_product'], 'outkit_only');
		//return $reserved;
		// Réduction seulement
		$sens = 1;
		// Tout réservé => Pas de mouvement
		if ($reserved[$r['fk_product']] >= $r['qty']) {
			$list[1][] = $product->ref.' - '.$product->label.' - lot '.$r['batch'].' - DDM '.$r['sellby'].' : quantité réservée '.$r['qty'];
			$qte = 0;
			$reserved[$r['fk_product']] -= $r['qty'];
			continue;
		}
		// Calcul en soustrayant le stock réservé
		elseif ($reserved[$r['fk_product']] && $reserved[$r['fk_product']] < $r['qty']) {
			$list[1][] = $product->ref.' - '.$product->label.' - lot '.$r['batch'].' - DDM '.$r['sellby'].' : quantité réservée '.$reserved[$r['fk_product']];
			$qte = $r['qty'] - $reserved[$r['fk_product']];
			$reserved[$r['fk_product']] = 0;
		}
		else {
			$qte = $r['qty'];
		}

		//trigger_error($qte);
		
		// PMP si on l'a, prix de revient sinon
		$price = !empty($r['pmp']) ?$r['pmp'] :$r['cost_price'];

		$res = $product->correct_stock_batch(
			$user,
			$r['fk_entrepot'],
			$qte,
			$sens,
			'Sortie Antigaspi RAZ pour panier',
			$price,
			$r['sellby_ts'], // dlc
			'', // dluo
			$r['batch'],
			'', // $inventorycode
			// origin ='' : project ?
			// origin_id = NULL : project_id ?
			// $disablestockchangeforsubproduct = 0
		);

		$list[$res>0 ?0 :2][] = $product->ref.' - '.$product->label.' - lot '.$r['batch'].' - DDM '.$r['sellby'].' : quantité supprimée '.$qte;
	}

	// On a forcément des choses à envoyer !
	if ($email_to && $email_from)
		mail($email_to,
			mb_detect_encoding($email_subject, 'ASCII', true) ?'=?utf-8?B?'.base64_encode($email_subject).'?=' :$email_subject,
			(empty($list[0]) && empty($list[1]) && empty($list[2])
				?'RAS : aucun produit périmé'."\r\n"
				:'')
			.(!empty($list[0])
				?'Les produits suivant on été sorti automatiquement du stock :'."\r\n"
				.'- '.implode("\r\n- ", $list[0])."\r\n\r\n"
				:'')
			.(!empty($list[1])
				?'Les produits suivant on été conservés (stock réservé) :'."\r\n"
				.'- '.implode("\r\n- ", $list[1])."\r\n"
				:'')
			.(!empty($list[2])
				?'Erreur de destockage pour les produits :'."\r\n"
				.'- '.implode("\r\n- ", $list[2])."\r\n"
				:''),
			'From: '.$email_from."\n".'Content-Type: text/plain; charset=UTF-8'."\n");

	return true;
}

public function stock_calculate($fk_product)
{
	global $conf, $user;
	
	//die();

	$datediff = !empty($conf->global->MMIPRODUCTDLUO_ANTIGASPI) ?$conf->global->MMIPRODUCTDLUO_ANTIGASPI :2;
	//var_dump($datediff); die();
	//trigger_error((string)$datediff);

	// Emplacements de stock
	$sql = 'SELECT p.fk_default_warehouse, s.rowid, s.fk_entrepot
		FROM `'.MAIN_DB_PREFIX.'product` p
		LEFT JOIN `'.MAIN_DB_PREFIX.'product_stock` s ON s.fk_product=p.rowid
		WHERE p.rowid='.$fk_product;
	//echo '<pre>'.$sql.'</pre>';
	$q = $this->db->query($sql);
	foreach($q as $row) {
		//var_dump($row);
		// @todo choper le premier emplacement de stock qui vient !
		if (empty($fk_product_stock)) {
			$fk_product_stock = $row['rowid'];
		}
		// @todo choper le bon entrepot, regarder le stock ce sera mieux
		if (empty($fk_entrepot)) {
			if (!empty($row['fk_default_warehouse'])) {
				$fk_entrepot = $row['fk_default_warehouse'];
				break;
			}
			elseif (!empty($row['fk_entrepot'])) {
				$fk_entrepot = $row['fk_entrepot'];
				break;
			}
		}
	}
	if (empty($fk_entrepot))
		$fk_entrepot = 1;
	//trigger_error($fk_entrepot);

	// sync associated product_stock after update
	if (!empty($fk_product_stock) && class_exists('mmi_prestasync'))
		mmi_prestasync::ws_trigger('stock', 'product_stock', 'osync', $fk_product_stock);

	// On calcule le stock des produits à DDM non courte
	$sql = 'SELECT p.rowid, pc.qty, SUM(IF((DATEDIFF(l.sellby, NOW()) >= '.$datediff.' OR pp2.kit_ddm_any=1) AND b.qty IS NOT NULL, b.qty, 0)) as qte, SUM(IF(b.qty IS NOT NULL, b.qty, 0)) as qte_tot
		FROM `'.MAIN_DB_PREFIX.'product_association` pc
		LEFT JOIN `'.MAIN_DB_PREFIX.'product_extrafields` pp2 ON pp2.fk_object=pc.fk_product_pere
		INNER JOIN `'.MAIN_DB_PREFIX.'product` p ON p.rowid=pc.fk_product_fils
		INNER JOIN `'.MAIN_DB_PREFIX.'product_stock` s ON s.fk_product=p.rowid
		INNER JOIN `'.MAIN_DB_PREFIX.'product_batch` b ON b.fk_product_stock=s.rowid
		INNER JOIN `'.MAIN_DB_PREFIX.'product_lot` l ON l.fk_product=p.rowid AND l.batch=b.batch
		WHERE pc.fk_product_pere='.$fk_product.'
			AND pc.qty>0
		GROUP BY p.rowid';
	// DATEDIFF(b.sellby, NOW()) as `datediff`, b.sellby, b.batch
	//echo '<pre>'.$sql.'</pre>'; die();
	//trigger_error($sql);

	$q = $this->db->query($sql);
	$qte = NULL;

	// Le plus petit
	foreach($q as $row) {
		//var_dump($row);
		//trigger_error($row);
		// Prise en compte du stock réservé (commandes en cours) par produit
		$qte_reserved = $this->reserved_qty($row['rowid']);
		$qte_new = floor(($row['qte']-$qte_reserved)/$row['qty']);
		//$row['qte_tot'];
		if (is_null($qte) || $qte_new<$qte)
			$qte = $qte_new;
	}

	// On n'a pas a prendre en compte les encours de kit car on a systématiquement les élements qui le composent dans les commandes.

	$product = new Product($this->db);
	$product->fetch($fk_product);

	// Pas de mouvement si pas de changement
	if ($product->stock_reel != $qte) {
		$sens = $qte-$product->stock_reel > 0 ?0 :1;
		$qte = $product->stock_reel-$qte > 0 ?$product->stock_reel-$qte :$qte-$product->stock_reel;
		//trigger_error($qte);

		if ($qte > 0)
			$res = $product->correct_stock(
				$user,
				$fk_entrepot,
				$qte,
				$sens,
				'Recalcul kit/panier depuis composant',
				0,
				date('YmdHis'),
				'',
				NULL,
				1);
	}

	return true;
}

/**
 * @todo prendre en compte les différents types de paramétrages de calcul de stock théorique
 * On ne compte que ce qui a été ajouté via un kit, le reste étant
 */
public function reserved_qty($fk_product, $kit_check='inkit_only')
{
	global $conf;

	$datediff = !empty($conf->global->MMIPRODUCTDLUO_ANTIGASPI) ?$conf->global->MMIPRODUCTDLUO_ANTIGASPI :2;

	$qty = 0;

	if ($kit_check) {
		// Check si on a du stock DDM antigaspi, dans ce cas les commandes hors kit se font uniquement sur de l'antigaspi et on n'a que les encours kit à prendre en considération pour le calcul de l'encours
		$sql = 'SELECT 1
			FROM `'.MAIN_DB_PREFIX.'product_stock` s
			INNER JOIN `'.MAIN_DB_PREFIX.'product_batch` b ON b.fk_product_stock=s.rowid
			INNER JOIN `'.MAIN_DB_PREFIX.'product_lot` l ON l.fk_product=s.fk_product AND l.batch=b.batch
			WHERE s.fk_product='.$fk_product.'
				AND b.qty > 0 AND DATEDIFF(l.sellby, NOW()) < '.$datediff.'
			LIMIT 1';
		$q = $this->db->query($sql);
		
		$isddm = $q->num_rows>0;
	}
	else {
		$isddm = false;
	}

	// Commandes en cours
	// c.fk_statut IN (0, 1, 2) => brouillons, validés, envoi en cours
	// st.statut >= 1 => Statut presta qui renvoie une commande valide
	$sql = 'SELECT COUNT(DISTINCT c.fk_soc) as nb_customers, COUNT(DISTINCT c.rowid) as nb, COUNT(cd.rowid) as nb_rows, SUM(cd.qty) as qty
		FROM '.MAIN_DB_PREFIX.'commandedet as cd
		INNER JOIN '.MAIN_DB_PREFIX.'commande as c ON c.rowid = cd.fk_commande
		LEFT JOIN '.MAIN_DB_PREFIX.'commandedet_extrafields as cd2 ON cd2.fk_object = cd.rowid
		WHERE cd.fk_product = '.$fk_product.'
			AND c.fk_statut IN (1, 2)'
			.($isddm && $kit_check=='inkit_only' ?' AND cd2.fk_parent_pack IS NOT NULL' :'')
			.($isddm && $kit_check=='outkit_only' ?' AND cd2.fk_parent_pack IS NULL' :'');
	/*
		LEFT JOIN '.MAIN_DB_PREFIX.'commande_extrafields c2 ON c2.fk_object=c.rowid
		LEFT JOIN '.MAIN_DB_PREFIX.'ps_order_state st ON st.id_order_state=c2.p_state
			AND (c.fk_statut in (1, 2) OR (c.fk_statut=0 AND st.statut >= 1))';
	*/
	//trigger_error($sql);
	$q = $this->db->query($sql);
	foreach($q as $row) {
		//trigger_error($row);
		$qty += $row['qty'] ?: 0;
	}

	// Expéditions effectuées sur les commandes en cours
	// e.fk_statut IN (1, 2) => validated et closed
	$sql = 'SELECT COUNT(DISTINCT e.fk_soc) as nb_customers, COUNT(DISTINCT e.rowid) as nb, COUNT(ed.rowid) as nb_rows, SUM(ed.qty) as qty
		FROM '.MAIN_DB_PREFIX.'expeditiondet as ed
		INNER JOIN '.MAIN_DB_PREFIX.'commandedet as cd ON cd.rowid=ed.fk_origin_line
		LEFT JOIN '.MAIN_DB_PREFIX.'commandedet_extrafields as cd2 ON cd2.fk_object = cd.rowid
		INNER JOIN '.MAIN_DB_PREFIX.'commande as c ON c.rowid = cd.fk_commande
		INNER JOIN '.MAIN_DB_PREFIX.'expedition as e ON e.rowid = ed.fk_expedition
		WHERE cd.fk_product = '.$fk_product.'
			AND c.fk_statut IN (1, 2)
			AND e.fk_statut IN (1, 2)'
			.($isddm && $kit_check=='inkit_only' ?' AND cd2.fk_parent_pack IS NOT NULL' :'')
			.($isddm && $kit_check=='outkit_only' ?' AND cd2.fk_parent_pack IS NULL' :'');
	//trigger_error($sql);
	$q = $this->db->query($sql);
	foreach($q as $row) {
		//trigger_error($row);
		$qty -= $row['qty'] ?: 0;
	}

	//var_dump($row);

	return $qty;
}

}

MMIProductDluo_Stock::__init();
