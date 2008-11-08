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


function __autoload($class) {
	// locate class
	$class = str_replace('::', '/', $class);
	$class = str_replace('.', '', $class); // avoid people trying to do ::..::..::..:: to escape from reality
	while($class[0]=='/') $class = substr($class, 1); // magic scope
	if (file_exists(PINETD_CLASS_ROOT.'/'.$class.'.class.php'))
		require_once(PINETD_CLASS_ROOT.'/'.$class.'.class.php');
}

function relativeclass(&$obj, $name) {
	$class = get_class($obj);
	while(1) {
		$pos = strrpos($class, '::');
		if ($pos === false) return '';
		$base = substr($class, 0, $pos);
		$newclass = $base . '::' . $name;
		$path = str_replace('::', '/', $newclass).'.class.php';
		while($path[0]=='/') $path = substr($path, 1); // magic scope
		if (file_exists(PINETD_CLASS_ROOT.'/'.$path)) return $newclass;
		$class = get_parent_class($class);
		if ($class === false) {
//*LEGACY*//return $name;
			return NULL; // no more depth available, forget it!
		}
	}
}

