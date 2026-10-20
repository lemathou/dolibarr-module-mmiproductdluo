<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2022 Moulin Mathieu <contact@iprospective.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    mmiproductdluo/admin/setup.php
 * \ingroup mmiproductdluo
 * \brief   MMIProductDluo setup page.
 */

// Load Dolibarr environment
require_once '../env.inc.php';
require_once '../main_load.inc.php';

$arrayofparameters = array(
	'MMIPRODUCTDLUO_PERIME'=>array('type'=>'string', 'enabled'=>1, 'default'=>'59'),
	// Parenthèse mal placée (préexistant) : le 'default' tombait HORS du tableau du paramètre,
	// ce qui privait ANTIGASPI de sa valeur par défaut ET ajoutait un faux paramètre nommé
	// « default » à l'écran de configuration.
	'MMIPRODUCTDLUO_ANTIGASPI'=>array('type'=>'string', 'enabled'=>1, 'default'=>'2'),
	'MMIPRODUCTDLUO_PROMO'=>array('type'=>'string','enabled'=>1, 'default'=>'30'),
	// RM3040 — bornes et remise du palier « antigaspi dernière minute », qui existaient en
	// boutique (J_PERIME_ALLONGE, REMISE_DM) et manquaient ici : sans elles l'ERP ne connaît
	// que trois paliers sur quatre, et la même DDM ne se lit pas pareil des deux côtés.
	'MMIPRODUCTDLUO_PERIME_ALLONGE'=>array('type'=>'string','enabled'=>1, 'default'=>'90'),
	'MMIPRODUCTDLUO_ORDER_PROMOPRICE_DM'=>array('type'=>'numeric','enabled'=>1, 'default'=>'70'),
	// RM3040 — lot proposé par défaut à la saisie d'une ligne : « courte_longue » (la DDM la
	// plus proche parmi date longue et date courte) ou « longue » (date longue seule). Dans les
	// deux cas, s'il ne reste que de l'antigaspi, on sert le lot le MOINS dégradé.
	// RM3492 — mode caisse : identifiant du GROUPE d'utilisateurs pour lequel la ligne se
	// valide seule au scan et dont un clic sur un lot valide la ligne. Vide ⇒ inactif.
	// Un groupe et non un droit : un droit est accorde d'office a tout administrateur.
	'MMIPRODUCTDLUO_CAISSE_GROUP'=>array('type'=>'numeric','enabled'=>1),
	'MMIPRODUCTDLUO_LOT_DEFAULT_MODE'=>array('type'=>'string','enabled'=>1, 'default'=>'courte_longue'),
	'MMIPRODUCTDLUO_STOCK_COMPOSED_DDM_AUTO'=>array('type'=>'yesno','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_FROM'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_TO'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_SUBJECT'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN'=>array('type'=>'yesno', 'enabled'=>1),
	'MMIPRODUCTDLUO_KIT_SHOW_LOT_STOCK'=>array('type'=>'yesno', 'enabled'=>1),
	
	'MMIPRODUCTDLUO_ORDER_PROMOPRICE_ENABLE'=>array('type'=>'yesno','enabled'=>1),
	'MMIPRODUCTDLUO_ORDER_PROMOPRICE_PROMO'=>array('type'=>'numeric','enabled'=>1, 'default'=>'10'),
	'MMIPRODUCTDLUO_ORDER_PROMOPRICE_ANTIGASPI'=>array('type'=>'numeric','enabled'=>1, 'default'=>'25'),
	
	'RECEPTION_BATCH_MUST_HAVE_DATE'=>array('type'=>'yesno','enabled'=>1),
	'STOCK_HIDE_ALL_EMPTY_BATCH_BY_DEFAULT'=>array('type'=>'yesno','enabled'=>1),
);

require_once('../../mmicommon/admin/mmisetup_1.inc.php');
