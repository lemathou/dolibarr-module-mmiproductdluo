<?php
/**
 * Copyright (C) 2022       MMI Mathieu Moulin      <contact@iprospective.fr>
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 *  Class of triggers for MyModule module
 */
class InterfaceStock_Composed extends DolibarrTriggers
{
	/**
	 * @var DoliDB Database handler
	 */
	protected $db;

	protected static $_stock_instance;

	public static function __init()
	{

	}

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "demo";
		$this->description = "MMI Product Stock Composed triggers";
		$this->version = 'development';
		$this->picto = 'logo@mmiproduct';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->mmiproductdluo->enabled)) return 0;

		global $db;
		$langs->loadLangs(array("mmiproductdluo@mmiproductdluo"));

		//var_dump($action); var_dump($object);
		switch($action) {
			case 'STOCK_MOVEMENT':
				/**
				 * @var MouvementStock $object Mouvement de stock
				 **/
				//var_dump($object); $db->rollback(); die();

				// Recalculer le stock des produits composés dont on est un élément
				if ($conf->global->MMIPRODUCTDLUO_STOCK_COMPOSED_DDM_AUTO) {
					// Initialisation instance
					if (empty(static::$_stock_instance)) {
						require_once DOL_DOCUMENT_ROOT.'/custom/mmiproductdluo/class/mmiproductdluo_stock.class.php';
						static::$_stock_instance = MMIProductDluo_Stock::_instance();
					}

					// check if product with ddm AND contained in pack
					$sql = 'SELECT pc.fk_product_pere
						FROM `'.MAIN_DB_PREFIX.'product` p
						INNER JOIN `'.MAIN_DB_PREFIX.'product_association` pc ON pc.fk_product_fils=p.rowid
						WHERE p.`rowid`='.$object->product_id
						.' AND p.tobatch=1';
	
					$q = $this->db->query($sql);
					foreach($q as $row) {
						//var_dump($row); die();
						// Ajoute à une liste, et calcule à la fin de la requête, car sur la même page il peut y avoir plusieurs produits d'un même pack donc cela génèrerait plusieurs calculs inutilement...
						static::$_stock_instance->add($row['fk_product_pere']);
					}
					//var_dump(static::$_stock_instance);
					//echo $sql; //$db->rollback(); die();
					//die();
	
					break;
				}
		}
		
		return 0;
	}
}

InterfaceStock_Composed::__init();
