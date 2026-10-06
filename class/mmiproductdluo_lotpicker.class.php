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
        $sql = 'SELECT pl.rowid AS fk_product_lot, pl.batch,'
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
                'fk_product_lot' => (int) $o->fk_product_lot,
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

    /** Paliers, du plus frais au plus proche de la péremption. Mêmes clés que PrestaShop. */
    const P_NORMAL    = 'normal';
    const P_COURTE    = 'date_courte';
    const P_ANTIGASPI = 'antigaspi';
    const P_DM        = 'antigaspi_dm';

    /** Modes de la valeur proposée par défaut (option de module). */
    const DEF_COURTE_LONGUE = 'courte_longue';
    const DEF_LONGUE        = 'longue';

    /**
     * Bornes en jours, lues dans la conf du module. Les mêmes valeurs qu'en boutique —
     * c'est la condition pour que la même DDM donne le même palier des deux côtés.
     */
    public static function bornes()
    {
        global $conf;
        $g = isset($conf->global) ? $conf->global : null;
        $lire = function ($nom, $defaut) use ($g) {
            return (int) (($g && isset($g->{$nom}) && $g->{$nom} !== '') ? $g->{$nom} : $defaut);
        };

        return array(
            'promo'          => $lire('MMIPRODUCTDLUO_PROMO', 30),
            'antigaspi'      => $lire('MMIPRODUCTDLUO_ANTIGASPI', 2),
            'perime'         => $lire('MMIPRODUCTDLUO_PERIME', 59),
            // RM3040 : absente du module jusqu'ici, alors que la boutique l'a (J_PERIME_ALLONGE).
            // Sans elle, le palier « dernière minute » n'existerait pas côté ERP.
            'perime_allonge' => $lire('MMIPRODUCTDLUO_PERIME_ALLONGE', 90),
        );
    }

    /** Mode de la valeur proposée par défaut, lu dans la conf du module. */
    public static function modeParDefaut()
    {
        global $conf;
        $v = (isset($conf->global) && !empty($conf->global->MMIPRODUCTDLUO_LOT_DEFAULT_MODE))
            ? $conf->global->MMIPRODUCTDLUO_LOT_DEFAULT_MODE : self::DEF_COURTE_LONGUE;

        return $v === self::DEF_LONGUE ? self::DEF_LONGUE : self::DEF_COURTE_LONGUE;
    }

    /**
     * PALIER d'un lot d'après son OFFSET en jours (DDM − jour de référence). PURE. RM3040.
     *
     * Règle identique à celle de la boutique (`PalierManager::palierPourOffset`) : c'est la
     * même remise consentie au même client, elle ne peut pas se lire différemment selon le
     * logiciel qui regarde.
     *
     * Le palier ainsi obtenu est calculé AU JOUR DE LA SAISIE puis figé sur la ligne : un lot
     * franchit une borne avec le temps, et une commande ne doit pas changer de bande toute
     * seule entre la saisie et la livraison (arbitrage Mathieu, 2026-09-29).
     *
     * @param int   $offsetDays          jours jusqu'à la DDM ; négatif = dépassée
     * @param int   $autoremoveDisabled  1 = lot maintenu en vente au-delà de la péremption
     * @param array $bornes              promo / antigaspi / perime / perime_allonge
     * @return string|null palier, ou null si le lot n'est PAS VENDABLE
     */
    public static function palierPourOffset($offsetDays, $autoremoveDisabled, array $bornes)
    {
        $o = (int) $offsetDays;
        if ($o > $bornes['promo']) {
            return self::P_NORMAL;
        }
        if ($o > $bornes['antigaspi']) {
            return self::P_COURTE;
        }
        if ($o >= -$bornes['perime']) {
            return self::P_ANTIGASPI;
        }
        // Dernière minute : seulement si la suppression automatique a été désactivée sur le lot.
        if ($o >= -$bornes['perime_allonge'] && (int) $autoremoveDisabled === 1) {
            return self::P_DM;
        }

        return null;
    }

    /** Le palier d'un lot rendu par `lotsForProduct` (il porte déjà `days`). PURE. */
    public static function palierDuLot($lot, array $bornes)
    {
        if (!isset($lot['days']) || $lot['days'] === null) {
            return null;   // lot sans DDM : on ne devine pas une bande
        }

        return self::palierPourOffset($lot['days'], isset($lot['antigaspi_autoremove_disabled'])
            ? $lot['antigaspi_autoremove_disabled'] : 0, $bornes);
    }

    /**
     * Lot PROPOSÉ par défaut à la saisie d'une ligne. PURE. RM3040.
     *
     * La règle a deux temps, et le sens s'inverse entre les deux — c'est voulu, ce n'est pas
     * une erreur (arbitrage Mathieu, 2026-09-29) :
     *
     *   1. de préférence dans « date longue » ou « date courte », la DDM la plus PROCHE : on
     *      écoule au plus près de la péremption sans brader par inadvertance, les vraies
     *      remises antigaspi restant un geste explicite ;
     *   2. s'il n'y a plus rien dans ces bandes, la DDM la plus LOINTAINE parmi le reste :
     *      contraint de puiser dans l'antigaspi, on sert le lot le MOINS dégradé plutôt que
     *      d'enfoncer le pire.
     *
     * @param array  $lots    sortie de lotsForProduct (triée DDM la plus proche d'abord)
     * @param array  $bornes
     * @param string $mode    DEF_COURTE_LONGUE (défaut) ou DEF_LONGUE (premier temps restreint)
     * @return array|null le lot proposé, ou null si aucun lot vendable
     */
    public static function choixParDefaut($lots, array $bornes, $mode = self::DEF_COURTE_LONGUE)
    {
        $lots = is_array($lots) ? array_values($lots) : array();
        $preferes = ($mode === self::DEF_LONGUE)
            ? array(self::P_NORMAL)
            : array(self::P_NORMAL, self::P_COURTE);

        $premier = null;   // 1er temps : DDM la plus proche dans les bandes préférées
        $repli   = null;   // 2e temps  : DDM la plus LOINTAINE parmi le reste (vendable)
        foreach ($lots as $lot) {
            $palier = self::palierDuLot($lot, $bornes);
            if ($palier === null) {
                continue;  // non vendable : jamais proposé
            }
            if (in_array($palier, $preferes, true)) {
                if ($premier === null || (int) $lot['days'] < (int) $premier['days']) {
                    $premier = $lot;
                }
            } elseif ($repli === null || (int) $lot['days'] > (int) $repli['days']) {
                $repli = $lot;
            }
        }

        return $premier !== null ? $premier : $repli;
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

    /**
     * Valeur stockée dans l'extrafield `fk_product_lot` de la ligne : l'IDENTIFIANT du lot.
     *
     * C'était le numéro de lot en texte dans la première version : une chaîne ne désigne un lot
     * qu'associée au produit de la ligne, elle devient un texte orphelin à la moindre faute de
     * frappe, et le champ était trop court (30 caractères pour des numéros qui en acceptent 128).
     */
    public static function toFieldValue($lot)
    {
        return isset($lot['fk_product_lot']) ? (int) $lot['fk_product_lot'] : 0;
    }

    /**
     * Rang d'une bande, du plus dégradé (0) au moins dégradé (3). PURE. RM3039.
     *
     * Sert à comparer deux bandes entre elles. Un palier inconnu ou absent rend `null` :
     * l'appelant doit alors s'abstenir de contraindre quoi que ce soit plutôt que de deviner.
     */
    public static function tierRank($tier)
    {
        $ranks = array(
            self::P_DM        => 0,
            self::P_ANTIGASPI => 1,
            self::P_COURTE    => 2,
            self::P_NORMAL    => 3,
        );

        return isset($ranks[$tier]) ? $ranks[$tier] : null;
    }

    /**
     * Les lots qu'on s'autorise à servir pour un palier VENDU, dans l'ordre où les prendre. PURE.
     *
     * Nommage en anglais (`tier` = palier) : la valeur stockée en base reste `palier`, le nom
     * de la colonne et ses valeurs ne se renomment pas sans casser les données.
     *
     * C'est la règle qui manquait au picking (RM3039). Dolibarr, laissé à lui-même, trie TOUS les
     * lots du produit par DDM croissante et sert le plus proche : il donnait donc de la date
     * courte à un client qui avait acheté — et payé — de la date longue, en silence.
     *
     * Deux principes, et ils ne sont pas symétriques (arbitrage Mathieu, 2026-10-05) :
     *
     *   1. **jamais moins bien que vendu** : aucun lot d'une bande plus dégradée que le palier de
     *      la ligne. C'est l'invariant, il ne se négocie pas — c'est une tromperie sur la
     *      marchandise, et le prix facturé ne correspondrait plus à ce qui est livré ;
     *   2. **mieux est acceptable** : si la bande vendue ne suffit pas, on complète avec une bande
     *      MOINS dégradée. On perd un peu de marge, on ne bloque pas l'expédition.
     *
     * L'ordre rendu fait le reste tout seul : à DDM croissante, les lots de la bande vendue
     * viennent naturellement avant ceux des bandes plus lointaines, donc on n'entame une
     * meilleure bande qu'une fois la bande vendue épuisée.
     *
     * Les lots NON VENDABLES (hors de toute bande : péremption dépassée sans le drapeau « dernière
     * minute ») sont écartés dans tous les cas, palier ou pas.
     *
     * @param array       $lots    sortie de `lotsForProduct` (chaque lot porte `days`)
     * @param string|null $tier    palier de la ligne de commande ; null/inconnu ⇒ aucune
     *                             restriction de bande, on rend seulement les lots vendables
     * @param array       $limits  cf. `bornes()`
     * @return array les lots autorisés, DDM la plus proche d'abord
     */
    public static function lotsForTier($lots, $tier, array $limits)
    {
        $lots = is_array($lots) ? array_values($lots) : array();
        $soldRank = self::tierRank($tier);

        $kept = array();
        foreach ($lots as $lot) {
            $lotTier = self::palierDuLot($lot, $limits);
            if ($lotTier === null) {
                continue;   // lot non vendable, ou sans DDM : on ne le sert pas
            }
            if ($soldRank !== null && self::tierRank($lotTier) < $soldRank) {
                continue;   // plus dégradé que ce qui a été vendu
            }
            $kept[] = $lot;
        }

        // DDM la plus proche d'abord. Les lots sans DDM ont déjà été écartés par palierDuLot().
        usort($kept, function ($a, $b) {
            $daysA = isset($a['days']) ? (int) $a['days'] : 0;
            $daysB = isset($b['days']) ? (int) $b['days'] : 0;
            if ($daysA === $daysB) {
                // À DDM égale, un ordre stable : le numéro de lot.
                $batchA = isset($a['batch']) ? (string) $a['batch'] : '';
                $batchB = isset($b['batch']) ? (string) $b['batch'] : '';

                return strcmp($batchA, $batchB);
            }

            return ($daysA < $daysB) ? -1 : 1;
        });

        return $kept;
    }
}
