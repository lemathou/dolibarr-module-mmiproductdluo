# MMIProductDluo — Gestion DLUO/DLC et lots : sélection de lots, antigaspi, stock composé, prix par bande et picking.

> Module Dolibarr **MMIProductDluo** (version 1.0), branche `20.0`.

## Fonctionnalités

### Fonctionnalités principales

- Dates DLUO/DLC et sélection de lots (`mmiproductdluo_lotpicker`, `ajax/lots_for_product.php`).
- Antigaspi (`ajax/antigaspi_toggle.php`) et stock composé (`mmiproductdluo_stock`, trigger dédié).
- Prix par bande, picking par palier/tiers et défauts (`test/*`).
- API dédiée (`api_mmiproductdluo`) et menus de gestion des lots.

### Écrans et menus

- **Gestion avancée Lots Produit (DDM/DLC/DLUO)** — `/mmiproductdluo/mmiproductdluoindex.php` (menu mmiproductdluo, droit `1`)

### Pages

- `admin/about.php`
- `admin/setup.php`

### Configuration

| Constante | Type | Rôle |
|---|---|---|
| `MMIPRODUCTDLUO_PERIME` | string | Délai péremption après DDM |
| `MMIPRODUCTDLUO_ANTIGASPI` | string | Délai mise en antigaspi avant DDM |
| `MMIPRODUCTDLUO_PROMO` | string | Délai promo avant DDM |
| `MMIPRODUCTDLUO_PERIME_ALLONGE` | string |  |
| `MMIPRODUCTDLUO_ORDER_PROMOPRICE_DM` | numeric |  |
| `MMIPRODUCTDLUO_CAISSE_GROUP` | numeric |  |
| `MMIPRODUCTDLUO_LOT_DEFAULT_MODE` | string |  |
| `MMIPRODUCTDLUO_STOCK_COMPOSED_DDM_AUTO` | yesno | Calcul automatique du stock des produits composés avec DDM |
| `MMIPRODUCTDLUO_ANTIGASPI_EMAIL_FROM` | string | Adresse expéditeur notifications |
| `MMIPRODUCTDLUO_ANTIGASPI_EMAIL_TO` | string | Adresse de réception notifications |
| `MMIPRODUCTDLUO_ANTIGASPI_EMAIL_SUBJECT` | string | Objet de l'email de notification |
| `MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN` | yesno | Recherche par EAN (douchette) pour créer une réception |
| `MMIPRODUCTDLUO_KIT_SHOW_LOT_STOCK` | yesno | Voir les stock par DDM dans le détail des kits |
| `MMIPRODUCTDLUO_ORDER_PROMOPRICE_ENABLE` | yesno | Afficher des remises sur les prix en fonction des DDM |
| `MMIPRODUCTDLUO_ORDER_PROMOPRICE_PROMO` | numeric | % de remise prix promo |
| `MMIPRODUCTDLUO_ORDER_PROMOPRICE_ANTIGASPI` | numeric | % de remise prix antigaspi |
| `RECEPTION_BATCH_MUST_HAVE_DATE` | yesno | Date (DDM ou DLC) obligatoire pour un lot |
| `STOCK_HIDE_ALL_EMPTY_BATCH_BY_DEFAULT` | yesno | Cacher les lots vides par défaut dans le stock |

### Hooks

- Contextes : `ordercard`
- Callbacks : `ddmSubquery`, `printFieldListSelect`, `printFieldListJoin`, `printFieldListWhere`, `printFieldListHaving`, `printFieldListStock`, `printFieldPreListTitle`, `printFieldListFilters`, `printFieldListOption`, `printFieldListTitle`, `printFieldListValue`, `printFieldListFooter`, `productAjaxPrice`, `printCommonFooter`, `estEnCaisse`, `lotPickerAssets`, `antigaspiButtonHtml`, `antigaspiAssets`

### Triggers

- `core/triggers/interface_99_modMMIProductDluo_stock_composed.class.php`

### Classes et composants

- `class/actions_mmiproductdluo.class.php`
- `class/api_mmiproductdluo.class.php`
- `class/mmiproductdluo_lotpicker.class.php`
- `class/mmiproductdluo_stock.class.php`
- `lib/mmiproductdluo.lib.php`

## Prérequis

- Dolibarr >= 11.0 (branche `20.0` : Dolibarr 20.0).
- Modules MMI : `mmicommon`, `mmiproduct`. **`mmicommon` doit être activé en premier.**
- Modules cœur : `productBatch`.

## Installation

1. Copier le module dans `htdocs/custom/mmiproductdluo` :

   ```sh
   cd htdocs/custom
   git clone <dépôt> mmiproductdluo
   ```

2. Dans Dolibarr : **Configuration → Modules**, activer d'abord `mmicommon`, puis ce module.
3. Vérifier les permissions dans la fiche des utilisateurs concernés.

## Licence

GPLv3 ou ultérieure — voir `COPYING`. Documentation sous GFDL.

## Changelog

Voir [CHANGELOG.md](CHANGELOG.md) (évolutions datées).
