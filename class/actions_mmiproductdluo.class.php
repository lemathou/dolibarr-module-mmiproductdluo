<?php

dol_include_once('custom/mmicommon/class/mmi_actions.class.php');
require_once 'mmiproductdluo_stock.class.php';

class ActionsMMIProductDluo extends MMI_Actions_1_0
{
	const MOD_NAME = 'mmiproductdluo';

    protected $stockalertzero;
    protected $useddm30asstock;
    protected $includeproductswithoutdesiredqty;
    protected $salert;
    protected $p_active;

    function __construct($db)
    {
        parent::__construct($db);

        // Global context
        $this->stockalertzero = GETPOST('stockalertzero', 'alpha');
        $this->useddm30asstock = GETPOST('useddm30asstock', 'alpha');
        $this->includeproductswithoutdesiredqty = GETPOST('includeproductswithoutdesiredqty', 'alpha');
        $this->salert = GETPOST('salert', 'alpha');
        $this->p_active = GETPOST('p_active', 'alpha');
    }

    // Boutons

	function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;

		// Produit => recalculer le stock
		if ($this->in_context($parameters, 'productcard')) {
            if (empty($object->array_options['options_compose']))
                return 0;
            //var_dump($action, $parameters, $object); die();
			$link = '?id='.$object->id.'&action=kit_stock_calculate';
			echo "<a class='butAction' href='".$link."'>".$langs->trans("MMIProductsDLUOStockCalculate")."</a>";

			return 0;
		}
		elseif ($this->in_context($parameters, 'ordersupplierdispatch')) {
			if (!empty($conf->global->MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN)) {
			?>
			<div id="batch_from">
				<br />
				Saisissez un code barre et tapez "Entrée" pour le rechercher :
				<input type="text" class="batch" value="" size="13" style="border: 1px solid gray;" placeholder="EAN douchette" />
			</div>
			<script>
				$(document).ready(function(){
					$('#batch_from').appendTo('.fichecenter');
					$('#batch_from input').on('keyup', function (e) {
						if (e.key === 'Enter' || e.keyCode === 13) {
							// Do something
							var val = $(this).val();
							$('form div table td.barcode').each(function(){
								var val2 = $(this).html();
								//alert(val2);
								if (val==val2) {
									var pos = $(this).data('pos');
									$(this).parent().parent().find('input#lot_number_0_'+pos).focus();
								}
							});
						}
					});
				});
			</script>
			<?php
			}
		}
		// Onglet stock de la fiche produit => charge le JS qui injecte les boutons bascule anti-gaspi sur chaque ligne de lot
		elseif ($this->in_context($parameters, 'stockproductcard')) {
			if (!empty($object->id)) {
				echo $this->antigaspiAssets($object->id);
			}
		}

		return 0;
	}

	function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;

		// Réception => Bloquer si on a pas saisi toutes les DDM
		if ($this->in_context($parameters, 'receptioncard')) {
			//echo '<script>$(document).ready(function(){ $(\'input[type=submit][name=add]\').click(function(){ var ok=false; alert(\'Missing dluo\'); return ok; }); });</script>';

			return 0;
		}

		return 0;
	}

	function doActions($parameters, &$object, &$action, $hookmanager)
	{
		if ($this->in_context($parameters, 'productcard') && $action=='kit_stock_calculate') {
            // Delayed stock calculate
            //var_dump($action, $parameters, $object); die();
            if (empty($object->array_options['options_compose']))
                return 0;

            MMIProductDluo_Stock::_instance()->stock_calculate($object->id);
        }

		return 0;
	}

    //

    /**
     * Sous-requête des quantités en lot, bornée au MÊME périmètre d'entrepôts que le
     * `stock_physique` du coeur (RM2867). Sans ce bornage, les colonnes DDM comptaient
     * TOUS les entrepôts alors que la colonne Stock est filtrée : deux nombres côte à côte
     * qui ne parlent pas du même stock dès qu'un entrepôt est sélectionné.
     */
    protected function ddmSubquery($parameters, $datecond, $select = 'SUM(pl2.qty)')
    {
        $warehouses = $parameters['list_warehouse_selected'] ?? ($parameters['list_warehouse'] ?? '');
        $scope = ($warehouses === '' ? '' : ' AND s2.fk_entrepot IN ('.$this->db->sanitize($warehouses).')');
        return '(SELECT '.$select
            .' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'.$scope
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch AND pl2.qty>0'
            .' WHERE pl.fk_product = p.rowid'.($datecond ? ' AND '.$datecond : '').')';
    }

    function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
        
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            // Stock DDM >= 30j
            $print .= ', COALESCE('.$this->ddmSubquery($parameters, 'DATEDIFF(pl.sellby, NOW()) > 30').', 0) AS ddm_qty_30';

            // Stock DDM >= 3j
            $print .= ', COALESCE('.$this->ddmSubquery($parameters, 'DATEDIFF(pl.sellby, NOW()) > 2 AND DATEDIFF(pl.sellby, NOW()) <= 30').', 0) AS ddm_qty_2';

            // Stock DDM >= -45j
            $print .= ', COALESCE('.$this->ddmSubquery($parameters, 'DATEDIFF(pl.sellby, NOW()) > -45 AND DATEDIFF(pl.sellby, NOW()) <= 2').', 0) AS ddm_qty_m45';

            // Le produit est-il RÉELLEMENT géré en lots ? (RM2867)
            // Sans cet indicateur, `COALESCE(ddm_qty_30, 0)` rend « aucun lot » indiscernable
            // de « 0 unité à plus de 30 jours » : un produit non périssable, jamais concerné
            // par la DDM, passait sous tous les seuils par construction.
            $print .= ', EXISTS(SELECT 1 FROM '.MAIN_DB_PREFIX.'product_lot as pl3'
                .' WHERE pl3.fk_product = p.rowid) AS ddm_has_lot';

            // liste Stock lots
            $print .= ', '.$this->ddmSubquery($parameters, '',
                'GROUP_CONCAT(pl.sellby, " : ", pl2.qty ORDER BY pl.sellby SEPARATOR "'."\n".'")').' AS ddm';
        }
		elseif ($this->in_context($parameters, 'ordersupplierdispatch')) {
			$print .= ', p.barcode';
		}
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    function printFieldListJoin($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            //var_dump($parameters);
            if ($this->p_active)
                $print = ' LEFT JOIN '.MAIN_DB_PREFIX.'product_extrafields as p2 ON p2.fk_object = p.rowid';
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            $print = '';
            if ($this->p_active)
                $print = ' AND (p2.p_active=1 AND p2.p_decli_disabled IS NULL)';
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    function printFieldListHaving($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            // RM2867 — « Utiliser DDM > 30j comme stock physique » ne passe PLUS par ici :
            // c'est une SUBSTITUTION de l'expression de stock (voir printFieldListStock), pas
            // un ` OR (…)` ajouté au groupe. L'ancien OR comparait de surcroît une quantité
            // brute au seuil `p.seuil_stock_alerte` en dur, là où le coeur compare le seuil
            // combiné `pse`/`p` à un stock PROJETÉ (commandes, expéditions, réceptions).
            //
            // Reste ici « Stock alerte non renseigné à 0 », qui élargit bien le groupe
            // « à réapprovisionner » — le coeur injecte désormais ce retour DANS la
            // parenthèse du groupe (RM2867), et non plus après.
            // « Stock alerte non renseigné à 0 » : comportement INCHANGÉ (même clause, même
            // point d'injection). Il porte le même défaut de priorité — son ` OR (…)` atterrit
            // hors des parenthèses du groupe — mais le corriger changerait un filtre que la
            // cliente utilise : traité à part, hors de ce ticket.
            if ($this->stockalertzero) {
                $print = ' OR (stock_physique <= IF(p.seuil_stock_alerte IS NOT NULL, p.seuil_stock_alerte, 0))';
            }
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Substitue l'expression de STOCK de la clause HAVING (RM2867).
     *
     * « Utiliser DDM > 30j comme stock physique » : le coeur évalue alors TOUS ses filtres
     * (stock désiré, seuil d'alerte, « alerte seulement ») contre la quantité en lot à plus
     * de 30 jours, au lieu du stock physique — ce que dit le libellé de la case.
     *
     * Repli pour les produits SANS lot : leur stock physique. Ils ne sont pas gérés en DDM ;
     * leur substituer 0 les ferait passer sous tous les seuils par construction (15 produits
     * concernés sur la base de dev).
     */
    function printFieldListStock($parameters, &$object, &$action, $hookmanager)
    {
        if ($this->in_context($parameters, 'stockreplenishlist') && $this->useddm30asstock) {
            $sqlstock = $parameters['sqlstock'] ?? 'stock_physique';
            $this->resprints = 'IF(ddm_has_lot, ddm_qty_30, '.$sqlstock.')';
        }

        return 0;
    }

    /**
     * Semble servir à afficher des filtrer globaux
     */
    function printFieldPreListTitle($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            //var_dump($parameters);
            $print = '<div class="inlin-block">Stock alerte non renseigné à 0 <input type="checkbox" name="stockalertzero"'.($this->stockalertzero ?' checked="checked"' :'').' /></div>'
            .'<div class="inlin-block">Utiliser DDM > 30j comme stock physique <input type="checkbox" name="useddm30asstock"'.($this->useddm30asstock ?' checked="checked"' :'').' /></div>'
            .'<div class="inlin-block">Uniquement actifs dans Prestashop <input type="checkbox" name="p_active"'.($this->p_active ?' checked="checked"' :'').' /></div>';
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Semble servir à afficher des filtrer globaux
     */
    function printFieldListFilters($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            //var_dump($parameters);
            if ($this->stockalertzero)
                $print .= '&stockalertzero=on';
            if ($this->useddm30asstock)
                $print .= '&useddm30asstock=on';
            if ($this->includeproductswithoutdesiredqty)
                $print .= '&includeproductswithoutdesiredqty=on';
            if ($this->salert)
                $print .= '&salert=on';
            if ($this->p_active)
                $print .= '&p_active=on';
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Semble servir à afficher des filtrer globaux
     */
    function printFieldListOption($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            // RM2869 — ce hook alimente la LIGNE DE FILTRES : il doit rendre autant de
            // <td> que printFieldListTitle rend de colonnes (5), sinon la ligne de filtres
            // est plus courte que celle des titres — rien au-dessus des colonnes DDM, et le
            // bloc de boutons de recherche décalé de 5 colonnes vers la gauche.
            // Les <input type="hidden"> étaient posés NUS dans le <tr> : le parseur HTML les
            // sort de la table (foster parenting). Ils partaient quand même avec le
            // formulaire — d'où le fait que ça « marchait » — mais ne comptaient pour
            // aucune cellule. Ils vivent désormais DANS la première cellule.
            $print = '<td class="liste_titre">'
            .'<input type="hidden" name="stockalertzero"'.($this->stockalertzero ?' value="on"' :'').' />'
            .'<input type="hidden" name="useddm30asstock"'.($this->useddm30asstock ?' value="on"' :'').' />'
            .'<input type="hidden" name="p_active"'.($this->p_active ?' value="on"' :'').' />'
            .'</td>'
            .'<td class="liste_titre">&nbsp;</td>'
            .'<td class="liste_titre">&nbsp;</td>'
            .'<td class="liste_titre">&nbsp;</td>'
            .'<td class="liste_titre">&nbsp;</td>';
        }
    
        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Semble servir à afficher des filtrer globaux
     */
    function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs;

        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            //var_dump($parameters);
            $print = '<td>Stock Phys.</td>'
            .'<td>DDM</td>'
            .'<td>DDM >&nbsp;-45j</td>'
            .'<td>DDM >&nbsp;2j</td>'
            .'<td>DDM >&nbsp;30j</td>';
        }
		elseif ($this->in_context($parameters, 'ordersupplierdispatch')) {
			if (!empty($conf->global->MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN)) {
				$print .= '<td class="right">'.$langs->trans("Barcode").'</td>';
			}
		}
		elseif ($this->in_context($parameters, 'productcompositioncard')) {
			// Qty in Lots
			if (!empty($conf->global->MMIPRODUCTDLUO_KIT_SHOW_LOT_STOCK)) {
				$print .= '<td class="center">'.$langs->trans('DDM').'</td>';
			}
		}
		elseif ($this->in_context($parameters, 'product_lotlist')) {
			// Colonne bascule rapide anti-gaspi (liste globale des lots)
			$langs->load('mmiproductdluo@mmiproductdluo');
			$print .= '<th class="center">'.$langs->trans('AntigaspiToggleColTitle').'</th>';
		}

        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Semble servir à afficher des filtrer globaux
     */
    function printFieldListValue($parameters, &$object, &$action, $hookmanager)
    {
		global $conf, $langs;

        $error = 0; // Error counter
        $print = '';
    
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            //var_dump($parameters);
            $objp = $parameters['objp'];
            $print = '<td class="right"'.($objp->stock_physique==0 ?' style="color: gray;"' :'').'>'.$objp->stock_physique.'</td>'
            .'<td class="right">'.$objp->ddm.'</td>'
            .'<td class="right"'.($objp->ddm_qty_m45==0 ?' style="color: gray;"' :'').'>'.$objp->ddm_qty_m45.'</td>'
            .'<td class="right"'.($objp->ddm_qty_2==0 ?' style="color: gray;"' :'').'>'.$objp->ddm_qty_2.'</td>'
            .'<td class="right"'.($objp->ddm_qty_30==0 ?' style="color: gray;"' :'').'>'.$objp->ddm_qty_30.'</td>';
        }
		elseif ($this->in_context($parameters, 'ordersupplierdispatch')) {
			if (!empty($conf->global->MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN)) {
				static $pos = 0;
				if (isset($parameters['objp'])) {
					$objp = $parameters['objp'];
					$print .= '<td class="barcode right" data-pos="'.$pos.'">'.$objp->barcode.'</td>';
					$pos++;
				}
			}
		}
		elseif ($this->in_context($parameters, 'productcompositioncard')) {
			// Qty in Lots
			//var_dump($object);
			$value = $parameters['value'];
			$timenow = time();
			$datenow = date('Y-m-d', $timenow);
			$datemonth = date('Y-m-d', $timenow+30*86400);
			if (!empty($conf->global->MMIPRODUCTDLUO_KIT_SHOW_LOT_STOCK)) {
				$sql = "SELECT pl.rowid, pl.sellby ddm, pl.batch, SUM(IF(pl2.qty>0,pl2.qty,0)) qty"
				.' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
				.' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'
				.' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch'
				.' WHERE pl.fk_product = '.$object->id.' AND pl2.qty!=0'
				.' GROUP BY pl.rowid';
				//echo $sql;
				$q = $this->db->query($sql);
				//var_dump($q, $this->db->lasterror);
				$ddm = '';
				if ($q) {
					while($row = $q->fetch_assoc()) {
						$ddm .= '<span style="'.($row['ddm']<$datenow ?'color: red;' :($row['ddm']<$datemonth ?'color: orange;' :'')).'">'.dol_print_date($row['ddm'], "%d/%m/%Y").'</span>&nbsp;:&nbsp;'.$row['qty'].'<br />';
					}
				}
				$print .= '<td class="right">'.$ddm.'</td>';
				//var_dump($value); die();
			}
		}
		elseif ($this->in_context($parameters, 'product_lotlist')) {
			// Bouton bascule rapide anti-gaspi (liste globale des lots)
			$objp = $parameters['obj'];
			$lotid = !empty($objp->rowid) ? (int) $objp->rowid : 0;
			$state = !empty($objp->options_antigaspi_autoremove_disabled) ? 1 : 0;
			$print .= '<td class="center">'.$this->antigaspiButtonHtml($lotid, $state).'</td>';
		}

        if (! $error)
        {
            $this->resprints = $print;
            return 0; // or return 1 to replace standard code
        }
        else
        {
            $this->errors[] = 'Error message';
            return -1;
        }
    }

    /**
     * Charge les assets (CSS + config + JS) une seule fois sur la liste globale des lots.
     */
    function printFieldListFooter($parameters, &$object, &$action, $hookmanager)
    {
        if ($this->in_context($parameters, 'product_lotlist')) {
            $this->resprints = $this->antigaspiAssets(0);
        }

        return 0;
    }

    /**
     * RM3040 — assets du choix de lot à la caisse : l'URL de l'endpoint et les libellés
     * traduits, puis le JS. Rien d'autre : la décision (aucun lot / un seul / plusieurs)
     * appartient au serveur, pour qu'elle soit la même à la caisse, à la synchro et au picking.
     */
    /**
     * Prix d'un produit selon la DDM de ses lots, à la saisie d'une ligne. RM3040.
     *
     * Cette règle vivait EN DUR dans `htdocs/product/ajax/products.php`, c'est-à-dire dans le
     * cœur de Dolibarr. Elle y connaissait deux bandes sur quatre — « date courte » et
     * « antigaspi » — et ignorait « antigaspi dernière minute » : un lot dépassé de 71 jours
     * repartait donc à −25 % au lieu de −70 % (commande 18705, lot 9494). Les constantes de
     * la bande manquante avaient bien été ajoutées, mais rien ne les lisait.
     *
     * Elle est ici parce que c'est du MÉTIER : les bornes, les bandes et leurs remises
     * appartiennent au module, pas au cœur. Le cœur n'ouvre qu'un point d'extension.
     *
     * La bande est calculée par `MMIProductDluo_LotPicker` — la même règle que le picking à
     * l'expédition (RM3039) et que le choix du lot à la caisse. Une troisième copie en dur
     * aurait divergé des deux autres, comme celle-ci avait divergé de la boutique.
     *
     * Limite CONNUE, héritée du code d'origine et non traitée ici : le lot retenu est le
     * premier par DDM, pas celui que l'opérateur a choisi sur la ligne. La remise peut donc
     * correspondre à un autre lot que celui qui sera servi. Cela touche au comportement de la
     * caisse et mérite son propre arbitrage.
     *
     * @param array  $parameters  'id' (produit), 'object' (Product chargé)
     * @param object $object
     * @param string $action
     * @param object $hookmanager
     * @return int 1 si le prix a été fourni, 0 sinon
     */
    public function productAjaxPrice(&$parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        if (empty($conf->global->MMIPRODUCTDLUO_ORDER_PROMOPRICE_ENABLE)) {
            return 0;
        }
        $fk_product = isset($parameters['id']) ? (int) $parameters['id'] : 0;
        $produit = isset($parameters['object']) ? $parameters['object'] : null;
        if ($fk_product <= 0 || !is_object($produit)) {
            return 0;
        }

        dol_include_once('/custom/mmiproductdluo/class/mmiproductdluo_lotpicker.class.php');
        if (!class_exists('MMIProductDluo_LotPicker')) {
            return 0;
        }

        $picker = new MMIProductDluo_LotPicker($db);
        $lots = $picker->lotsForProduct($fk_product);
        if (empty($lots)) {
            return 0;
        }
        // `lotsForProduct` trie DDM la plus proche d'abord : on garde le comportement d'origine
        // (le lot le plus ancien donne le prix), cf. la limite connue au docblock.
        $lot = $lots[0];
        $bornes = MMIProductDluo_LotPicker::bornes();
        $bande = MMIProductDluo_LotPicker::palierDuLot($lot, $bornes);
        if ($bande === null) {
            return 0;   // lot non vendable : aucune remise, et surtout aucune vente
        }

        // La table des remises vit dans le lotpicker : le JS qui repose le prix quand
        // l'opérateur change de lot lit la MÊME, via l'endpoint AJAX.
        $remise = MMIProductDluo_LotPicker::discountForTier($bande);
        if ($remise <= 0) {
            return 0;   // bande sans remise (date longue) : le prix normal s'applique
        }

        // Le résultat se dépose dans `$this->results`, JAMAIS dans `$hookmanager->resArray` :
        // `executeHooks()` vide `resArray` à l'entrée, puis le REMPLACE à la sortie par la fusion
        // des `->results` de chaque module (cf. hookmanager.class.php, l. 212 et 325). Écrire
        // directement dans `resArray` revient donc à écrire dans une variable que le cœur jette
        // juste après : le hook renvoyait bien 1, mais le cœur voyait un tableau vide et gardait
        // sa remise à 0. C'est la convention déjà documentée par MMI_Actions_1_0::$results.
        $this->results = array(
            'discount'         => $remise,
            'price_ht'         => price($produit->price),
            'price_ttc'        => price($produit->price_ttc),
            'qty'              => isset($lot['qty']) ? $lot['qty'] : 0,
            'price_base_type'  => $produit->price_base_type,
            // Normalisée ici aussi : le hook ne doit pas dépendre de ce que le cœur fait de
            // sa valeur. Un taux brut (« 5.500 ») ne correspond à aucune option du <select>
            // des taux, et la ligne repart au taux par défaut.
            'tva_tx'           => price2num($produit->tva_tx),
            'default_vat_code' => $produit->default_vat_code,
            'palier'           => $bande,
        );

        return 1;
    }

    /**
     * Charge le choix de lot sur l'écran de commande, qui sert de CAISSE. RM3040.
     *
     * Pourquoi ici et pas dans `addMoreActionsButtons` comme à l'origine : `commande/card.php`
     * n'affiche la barre d'actions que `if ($action != 'presend' && $action != 'editline')`.
     * Le hook n'était donc PAS appelé pendant l'édition d'une ligne — exactement le moment où
     * le champ natif, qui liste tous les lots de tous les produits, est le plus gênant. Le JS
     * n'était tout simplement pas chargé là.
     *
     * `printCommonFooter` s'exécute en fin de page quel que soit `$action`, et à un endroit où
     * injecter un <script> est valide — au contraire de `printObjectLine`, qui tomberait au
     * milieu d'un <table>.
     *
     * @param array  $parameters
     * @param object $object
     * @param string $action
     * @param object $hookmanager
     * @return int
     */
    public function printCommonFooter(&$parameters, &$object, &$action, $hookmanager)
    {
        if ($this->in_context($parameters, 'ordercard')) {
            echo $this->lotPickerAssets();
        }

        return 0;
    }

    /**
     * L'opérateur travaille-t-il en caisse ? RM3492.
     *
     * Par APPARTENANCE À UN GROUPE, et non par un droit : un droit ne sait pas exclure les
     * administrateurs. `User::getrights()` contient un bloc « Force permission on user for
     * admin », et `init()` accorde en plus tout nouveau droit à chaque compte admin
     * (DolibarrModules::insert_permissions, « SELECT rowid FROM llx_user WHERE admin = 1 »).
     * Résultat mesuré en production le 2026-10-20 : le mode caisse s'est trouvé actif pour les
     * 7 administrateurs, alors qu'il ne devait l'être que pour la caisse. L'appartenance à un
     * groupe, elle, ne s'hérite pas — c'est la seule façon de désigner vraiment des personnes.
     *
     * Groupe non renseigné ⇒ fonction inactive : aucun effet de bord par défaut, et le
     * comportement se débraye en vidant l'option, sans livraison.
     *
     * @param  object $u utilisateur courant
     * @return bool
     */
    protected function estEnCaisse($u)
    {
        global $conf;

        $gid = isset($conf->global->MMIPRODUCTDLUO_CAISSE_GROUP) ? (int) $conf->global->MMIPRODUCTDLUO_CAISSE_GROUP : 0;
        if ($gid <= 0 || !is_object($u) || empty($u->id)) {
            return false;
        }
        $sql = 'SELECT fk_user FROM '.MAIN_DB_PREFIX.'usergroup_user'
            .' WHERE fk_usergroup = '.$gid.' AND fk_user = '.((int) $u->id);
        $res = $this->db->query($sql);
        if (!$res) {
            return false;   // en cas de doute, on n'active rien
        }
        $dedans = ($this->db->num_rows($res) > 0);
        $this->db->free($res);

        return $dedans;
    }

    protected function lotPickerAssets()
    {
        global $langs, $conf;

        global $user;

        $langs->load('mmiproductdluo@mmiproductdluo');
        $conf_js = array(
            'url'   => dol_buildpath('/custom/mmiproductdluo/ajax/lots_for_product.php', 1),
            // RM3492 — mode caisse : la ligne se valide seule au scan, et un clic sur un lot
            // valide la ligne. Réservé aux membres du GROUPE désigné en configuration, l'écran
            // servant aussi à saisir des commandes ordinaires — poser la ligne sous les doigts
            // de quelqu'un venu ajuster un prix serait pire que le geste qu'on lui épargne.
            'autovalide' => $this->estEnCaisse($user) ? 1 : 0,
            'langs' => array(
                'choose' => $langs->trans('MMILotPickerChoose'),
                'nolot'  => $langs->trans('MMILotPickerNoLot'),
                'auto'   => $langs->trans('MMILotPickerAuto'),
            ),
        );

        // Empreinte du fichier dans l'URL : sans elle, un navigateur qui a déjà chargé une
        // version du script la garde, et un correctif déployé reste invisible côté poste —
        // on croit corriger sans effet, ce qui s'est produit (RM3040).
        // dol_buildpath(..., 0) rend le chemin FICHIER (il tient compte du répertoire alternatif
        // des modules), (..., 1) rend l'URL.
        $v = @filemtime(dol_buildpath('/custom/mmiproductdluo/js/lotpicker.js', 0));
        $url = dol_buildpath('/custom/mmiproductdluo/js/lotpicker.js', 1).($v ? '?v='.$v : '');

        // Masquer le champ natif par CSS plutôt qu'en manipulant le DOM : select2 se greffe
        // quand il veut, et retirer son habillage après coup s'est révélé être une course.
        // `.fieldline_options_<clé>` est le conteneur que Dolibarr donne à chaque extrafield de
        // LIGNE (commonobject.class.php, showOptionals en mode `line`) : tout ce que select2
        // fabrique à l'intérieur tombe avec, quel que soit le moment.
        // La règle ne mord que si le picker a posé sa classe sur <body> — sans quoi un échec du
        // JS laisserait l'opérateur sans aucun champ.
        $css = 'body.mmi-lotpicker-actif .fieldline_options_fk_product_lot .select2,'
            .'body.mmi-lotpicker-actif .fieldline_options_fk_product_lot .select2-container,'
            .'body.mmi-lotpicker-actif .fieldline_options_fk_product_lot select#options_fk_product_lot'
            .'{display:none !important;}';

        return '<style>'.$css.'</style>'
            .'<script>window.mmiLotPicker = '.json_encode($conf_js).';</script>'
            .'<script src="'.$url.'"></script>';
    }

    /**
     * Construit le bouton <a> de bascule de l'extrafield antigaspi_autoremove_disabled d'un lot.
     * Markup identique côté JS (buildButton) pour un comportement uniforme.
     *
     * @param int $lotid  rowid du product_lot
     * @param int $state  1 = sortie auto désactivée (lot protégé), 0 = sortie auto active
     * @return string
     */
    protected function antigaspiButtonHtml($lotid, $state)
    {
        global $langs;

        $langs->load('mmiproductdluo@mmiproductdluo');
        if (empty($lotid)) {
            return '';
        }
        $state = $state ? 1 : 0;
        $title = $state ? $langs->trans('AntigaspiAutoremoveDisabledHelp') : $langs->trans('AntigaspiAutoremoveActiveHelp');

        return '<a href="#" class="mmi-antigaspi-toggle '.($state ? 'mmi-antigaspi-on' : 'mmi-antigaspi-off').'"'
            .' data-lotid="'.((int) $lotid).'" data-state="'.$state.'" title="'.dol_escape_htmltag($title).'">'
            .'<span class="fa '.($state ? 'fa-lock' : 'fa-unlock-alt').'"></span></a>';
    }

    /**
     * Émet (une seule fois par page) le CSS, la configuration JS et le chargement du script.
     *
     * @param int $fk_product  si > 0, demande au JS d'injecter les boutons dans l'onglet stock du produit
     * @return string
     */
    protected function antigaspiAssets($fk_product = 0)
    {
        global $langs;

        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        $langs->load('mmiproductdluo@mmiproductdluo');

        $ajaxurl = dol_buildpath('/mmiproductdluo/ajax/antigaspi_toggle.php', 1);
        $jsurl = dol_buildpath('/mmiproductdluo/js/antigaspi.js', 1);

        $conf = array(
            'ajaxurl' => $ajaxurl,
            'token' => newToken(),
            'fkproduct' => $fk_product ? (int) $fk_product : 0,
            'labels' => array(
                'on' => $langs->trans('AntigaspiAutoremoveDisabledHelp'),
                'off' => $langs->trans('AntigaspiAutoremoveActiveHelp'),
            ),
        );

        $out = '<style>'
            .'.mmi-antigaspi-toggle{text-decoration:none;cursor:pointer;}'
            .'.mmi-antigaspi-toggle .fa{font-size:1.05em;}'
            .'.mmi-antigaspi-on .fa{color:#bc9526;}'
            .'.mmi-antigaspi-off .fa{color:#bbb;}'
            .'.mmi-antigaspi-toggle.mmi-loading{opacity:.4;pointer-events:none;}'
            .'</style>';
        $out .= '<script>window.mmiAntigaspiConf = '.json_encode($conf).';</script>';
        $out .= '<script src="'.$jsurl.'"></script>';

        return $out;
    }
}

ActionsMMIProductDluo::__init();
