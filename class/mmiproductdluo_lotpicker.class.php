<?php
/**
 * MMIProductDluo_LotPicker — RM3040 : quels lots proposer pour une ligne de commande.
 *
 * Le socle commun aux trois usages du lot par ligne :
 *   · la CAISSE (saisie code-barres) : un seul lot → retenu d'office, plusieurs → à choisir ;
 *   · la synchro PrestaShop, qui impose le lot déjà alloué côté boutique ;
 *   · le picking, qui utilisera le lot retenu au lieu de re-décider en FIFO.
 *
 * Une seule règle de tri, ici : **la DDM la plus proche d'abord**. C'est ce qu'on veut
 * écouler, et c'est ce que l'opérateur doit voir en premier — un tri différent selon
 * l'écran finirait par vendre le mauvais lot.
 *
 * La DDM de référence est `sellby` (à vendre avant), comme partout dans ce module ;
 * `eatby` est rendu à titre indicatif. Un lot sans DDM passe en dernier : on ne devine pas.
 */
class MMIProductDluo_LotPicker
{
    /** @var DoliDB */
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Les lots VENDABLES d'un produit : stock > 0, triés DDM la plus proche d'abord.
     *
     * @param int $fk_product   produit
     * @param int $fk_entrepot  0 = tous les entrepôts (les quantités sont alors cumulées par lot)
     * @return array [['batch','sellby','eatby','qty','antigaspi_autoremove_disabled','days'], …]
     */
    public function lotsForProduct($fk_product, $fk_entrepot = 0)
    {
        $fk_product = (int) $fk_product;
        if ($fk_product <= 0) {
            return array();
        }
        $sql = 'SELECT pl.batch,'
            .' pl.sellby, pl.eatby,'
            .' SUM(pb.qty) AS qty,'
            .' DATEDIFF(pl.sellby, NOW()) AS days,'
            .' COALESCE(ple.antigaspi_autoremove_disabled, 0) AS antigaspi_autoremove_disabled'
            .' FROM '.MAIN_DB_PREFIX.'product_lot AS pl'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_stock AS ps ON ps.fk_product = pl.fk_product'
            .' INNER JOIN '.MAIN_DB_PREFIX.'product_batch AS pb'
            .'   ON pb.fk_product_stock = ps.rowid AND pb.batch = pl.batch'
            .' LEFT JOIN '.MAIN_DB_PREFIX.'product_lot_extrafields AS ple ON ple.fk_object = pl.rowid'
            .' WHERE pl.fk_product = '.$fk_product
            .' AND pb.qty > 0';
        if ((int) $fk_entrepot > 0) {
            $sql .= ' AND ps.fk_entrepot = '.((int) $fk_entrepot);
        }
        $sql .= ' GROUP BY pl.batch, pl.sellby, pl.eatby, ple.antigaspi_autoremove_disabled'
            .' HAVING qty > 0'
            // DDM la plus proche d'abord ; les lots sans DDM ferment la marche (ISNULL=1 en dernier)
            .' ORDER BY ISNULL(pl.sellby), pl.sellby ASC, pl.batch ASC';

        $res = $this->db->query($sql);
        if (!$res) {
            return array();
        }
        $out = array();
        while ($o = $this->db->fetch_object($res)) {
            $out[] = array(
                'batch'  => $o->batch,
                'sellby' => $o->sellby,
                'eatby'  => $o->eatby,
                'qty'    => (float) $o->qty,
                'days'   => ($o->days === null ? null : (int) $o->days),
                'antigaspi_autoremove_disabled' => (int) $o->antigaspi_autoremove_disabled,
            );
        }
        $this->db->free($res);

        return $out;
    }

    /**
     * Décision de caisse : que faire à la lecture d'un code-barres ?
     *
     * Pure (aucun accès base) pour être testable : on lui passe les lots, elle rend la
     * conduite à tenir. C'est le cœur de l'exigence — la caisse ne doit s'arrêter QUE
     * lorsqu'il y a vraiment un choix à faire.
     *
     * @param array $lots  sortie de lotsForProduct()
     * @return array ['mode' => 'none'|'auto'|'choose', 'lot' => array|null, 'lots' => array]
     *   none   : produit sans lot en stock → ne pas bloquer la vente
     *   auto   : un seul lot → retenu sans interaction
     *   choose : plusieurs lots → présenter la sélection, le premier étant le meilleur choix
     */
    public static function decide($lots)
    {
        $lots = is_array($lots) ? array_values($lots) : array();
        if (count($lots) === 0) {
            return array('mode' => 'none', 'lot' => null, 'lots' => array());
        }
        if (count($lots) === 1) {
            return array('mode' => 'auto', 'lot' => $lots[0], 'lots' => $lots);
        }

        return array('mode' => 'choose', 'lot' => null, 'lots' => $lots);
    }

    /**
     * Libellé d'un lot pour l'opérateur : « LOT123 — DDM 21/10/2026 — 12 en stock ».
     * Court par nécessité : il s'affiche dans une liste qu'on parcourt au clavier.
     */
    public static function label($lot, $langs = null)
    {
        $txt = isset($lot['batch']) ? $lot['batch'] : '';
        $ddm = isset($lot['sellby']) ? $lot['sellby'] : '';
        if ($ddm && $ddm !== '0000-00-00') {
            $ts = strtotime($ddm);
            $txt .= ' — DDM '.($ts ? date('d/m/Y', $ts) : $ddm);
        } else {
            $txt .= ' — sans DDM';
        }
        if (isset($lot['qty'])) {
            $txt .= ' — '.((float) $lot['qty'] == (int) $lot['qty'] ? (int) $lot['qty'] : $lot['qty']).' en stock';
        }

        return $txt;
    }

    /** Valeur stockée dans l'extrafield de ligne : le batch, seul (la DDM se relit du lot). */
    public static function toFieldValue($lot)
    {
        return isset($lot['batch']) ? (string) $lot['batch'] : '';
    }
}
