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
