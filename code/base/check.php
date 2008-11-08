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

// check php version
if (substr(PHP_VERSION, 0, 1) < 5) {
	echo "Error: you need at least PHP 5.3.0 to run pinetd\n";
	exit(3);
}
//*LEGACY*//if (0) {
if (!version_compare(PHP_VERSION, '5.3', '>=')) { //*LEGACY*//
	echo "Error: you need at least PHP 5.3.0 to run pinetd\n";
	exit(3);
}

// check php modules
$required = array(
	// xml stuff required
	'dom',
	'xml',
	'SimpleXML',
	'libxml',

	'tokenizer', // used for some analysis //*LEGACY*//
	'pcre', // pcre is useful
	'sockets', // what will we do without sockets ?!
	'SQLite', // storage subclass
	'mysqli', // storage subclass
	'mhash', // mhash are always useful
	'mbstring', // can do stuff iconv can't do (like decode/encode IMAP UTF-7)
	'iconv', // convert stuff
	'hash', //*LEGACY*//
	'gd', // we may want to generate gfx
	'ftp', // ftp access ?
	'filter', // filters (useful to qualify ips) //*LEGACY*//
	'date', // what will we do without that?
	'zlib', // zlib might be useful for a bunch of things
);
$list = get_loaded_extensions();

foreach($list as $ext) {
	define('PINETD_GOT_'.strtoupper($ext), true);
}

foreach ($required as $ext) {
	if (!defined('PINETD_GOT_'.strtoupper($ext))) {
		echo "Error: you are missing extension $ext\n";
		exit(2);
	}
}

if ((defined('PINETD_GOT_POSIX')) && (defined('PINETD_GOT_PCNTL'))) {
	define('PINETD_CAN_FORK', true);
	if ((function_exists('chroot')) && (posix_getuid() == 0)) {
		define('PINETD_CAN_CHROOT', true);
		define('PINETD_CAN_SUID', true);
	} else {
		define('PINETD_CAN_CHROOT', false);
		define('PINETD_CAN_SUID', false);
	}
} else {
	define('PINETD_CAN_FORK', false);
	define('PINETD_CAN_CHROOT', false);
	define('PINETD_CAN_SUID', false);
}

unset($required);
unset($list);
unset($ext);

