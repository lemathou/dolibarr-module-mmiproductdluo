<?php

dol_include_once('custom/mmicommon/class/mmi_actions.class.php');
require_once 'mmiproductdluo_stock.class.php';

class ActionsMMIProductDluo extends MMI_Actions_1_0
{stockalertzero
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

    function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
    {
        $error = 0; // Error counter
        $print = '';
        
        if ($this->in_context($parameters, 'stockreplenishlist')) {
            // Stock DDM >= 30j
            $print .= ', COALESCE((SELECT SUM(pl2.qty)'
            .' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch AND pl2.qty>0'
            .' WHERE pl.fk_product = p.rowid AND DATEDIFF(pl.eatby, NOW()) > 30), 0) AS ddm_qty_30';
            // @todo : mettre le filtrage par stock !

            // Stock DDM >= 3j
            $print .= ', COALESCE((SELECT SUM(pl2.qty)'
            .' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch AND pl2.qty>0'
            .' WHERE pl.fk_product = p.rowid AND DATEDIFF(pl.eatby, NOW()) > 2 AND DATEDIFF(pl.eatby, NOW()) <= 30), 0) AS ddm_qty_2';

            // Stock DDM >= -45j
            $print .= ', COALESCE((SELECT SUM(pl2.qty)'
            .' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch AND pl2.qty>0'
            .' WHERE pl.fk_product = p.rowid AND DATEDIFF(pl.eatby, NOW()) > -45 AND DATEDIFF(pl.eatby, NOW()) <= 2), 0) AS ddm_qty_m45';

            // liste Stock lots
            $print .= ', (SELECT GROUP_CONCAT(pl.eatby, " : ", pl2.qty SEPARATOR "'."\n".'")'
            .' FROM '.MAIN_DB_PREFIX.'product_lot as pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as s2 ON s2.fk_product = pl.fk_product'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch as pl2 ON pl2.fk_product_stock = s2.rowid AND pl2.batch = pl.batch AND pl2.qty>0'
            .' WHERE pl.fk_product = p.rowid) AS ddm';

            //$print = ', GROUP_CONCAT(pl.eatby SEPARATOR " ") ddm_dates, SUM(pl2.qty) ddm_qte';
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
                $print = ' AND p2.p_active=1';
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
            if ($this->useddm30asstock) {
                if ($this->stockalertzero)
                    $print = ' OR (ddm_qty_30 <= IF(p.seuil_stock_alerte IS NOT NULL, p.seuil_stock_alerte, 0))';
                else
                    $print = ' OR (ddm_qty_30 < p.seuil_stock_alerte)';
            }
            elseif ($this->stockalertzero) {
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
            //var_dump($parameters);
            $print = '<input type="hidden" name="stockalertzero"'.($this->stockalertzero ?' value="on"' :'').' />'
            .'<input type="hidden" name="useddm30asstock"'.($this->useddm30asstock ?' value="on"' :'').' />'
            .'<input type="hidden" name="p_active"'.($this->p_active ?' value="on"' :'').' />';
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
        global $conf;

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
}

ActionsMMIProductDluo::__init();
