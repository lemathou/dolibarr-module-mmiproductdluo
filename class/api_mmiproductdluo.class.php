<?php

use Luracast\Restler\RestException;

dol_include_once('custom/mmicommon/class/mmi_prestasyncapi.class.php');
dol_include_once('custom/mmiproductdluo/class/mmiproductdluo_stock.class.php');

class MMIProductDLUOApi extends MMI_PrestasyncApi_1_0
{
	/**
	 * Recalculate Kit Stock
	 *
	 * @param int   $id             Id of product kit
	 * @param array $request_data   Datas
	 * @return int
	 *
	 * @url kit_stock_calculate/{id}
	 */
	function kit_stock_calculate($id, $request_data=[])
	{
		//trigger_error('kit_stock_calculate:'.$id);
		// Delayed stock calculate
		static::_getsynchrouser();
		MMIProductDluo_Stock::_instance()->stock_calculate($id);
		return 1;
	}

	/**
	 * Remove old DDM lots
	 *
	 * @param array $request_data   Datas
	 * @return int
	 *
	 * @url lot_ddm_old_remove/
	 */
	function lot_ddm_old_remove($request_data=[])
	{
		//trigger_error('kit_stock_calculate:'.$id);
		// Delayed stock calculate
		static::_getsynchrouser();
		$ret = MMIProductDluo_Stock::_instance()->lot_ddm_old_remove();
		//return $ret;
		//var_dump('hhh');
		return 1;
	}
}

MMIProductDLUOApi::__init();
