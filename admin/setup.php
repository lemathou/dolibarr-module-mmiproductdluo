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
	'MMIPRODUCTDLUO_ANTIGASPI'=>array('type'=>'string', 'enabled'=>1), 'default'=>'2',
	'MMIPRODUCTDLUO_PROMO'=>array('type'=>'string','enabled'=>1, 'default'=>'30'),
	'MMIPRODUCTDLUO_STOCK_COMPOSED_DDM_AUTO'=>array('type'=>'yesno','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_FROM'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_TO'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_ANTIGASPI_EMAIL_SUBJECT'=>array('type'=>'string','enabled'=>1),
	'MMIPRODUCTDLUO_SUPPLIERORDER_DISPATCH_SEARCHBYEAN'=>array('type'=>'yesno', 'enabled'=>1),
);

require_once('../../mmicommon/admin/mmisetup_1.inc.php');
