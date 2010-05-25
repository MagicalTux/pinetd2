<?php
/*   Portable INET daemon v2 in PHP
 *   Copyright (C) 2007 Mark Karpeles <mark@kinoko.fr>
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, write to the Free Software
 *   Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


define('PINETD_ROOT', dirname(__DIR__)); // root of pinetd installation
define('PINETD_CODE', PINETD_ROOT.'/code'); // path where code is stored
define('PINETD_CLASS_ROOT', PINETD_CODE.'/classes'); // path to classes
define('PINETD_STORE', PINETD_ROOT.'/store'); // path to data store

require_once(PINETD_CODE.'/base/check.php'); // perform compatibility checks

require_once(PINETD_CODE.'/base/root.php');

