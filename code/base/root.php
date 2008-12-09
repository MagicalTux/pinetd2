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

declare(ticks = 1); // for signals

define('PINETD_VERSION', '2.0.0alpha');

require_once(PINETD_CODE.'/base/classfunc.php');
require_once(PINETD_CODE.'/base/basefunc.php');

$GLOBALS['CONFIG'] = pinetd\ConfigManager::invoke();

pinetd\Logger::log(pinetd\Logger::LOG_DEBUG, 'pinetd v' . PINETD_VERSION . ' running on PHP/'.PHP_VERSION.' on a '.php_uname('a'));

// check a few things and display warnings if needed
if (isset($GLOBALS['CONFIG']->Global->Security->SUID)) {
	// check if we can SUID
	if (!PINETD_CAN_SUID) {
		pinetd\Logger::log(pinetd\Logger::LOG_WARN, 'SUID security level is defined, however it is not possible to SUID in current conditions (not root, or not a POSIX system, or no POSIX extensions available)');
	}
}

if (isset($GLOBALS['CONFIG']->Global->Security->Chroot)) {
	if (!PINETD_CAN_CHROOT) {
		pinetd\Logger::log(pinetd\Logger::LOG_WARN, 'Warning: Chroot security level is defined, however it is not possible to chroot() in current conditions (not root, or not a POSIX system, or no POSIX extensions available)');
	}
}

if (isset($GLOBALS['CONFIG']->Global->Security->Fork)) {
	if (!PINETD_CAN_FORK) {
		pinetd\Logger::log(pinetd\Logger::LOG_WARN, 'Warning: Fork security level is defined, however it is not possible to fork() in current conditions (not a POSIX system, or no POSIX extensions available)');
	}
}

if ((!(isset($GLOBALS['CONFIG']->Global->Security->Fork))) || (!PINETD_CAN_FORK)) {
	if (isset($GLOBALS['CONFIG']->Global->Security->SUID)) {
		pinetd\Logger::log(pinetd\Logger::LOG_ERR, 'Can\'t SUID without fork(), FORGET IT!');
		exit(11);
	}
	if (isset($GLOBALS['CONFIG']->Global->Security->Chroot)) {
		pinetd\Logger::log(pinetd\Logger::LOG_ERR, 'Can\'t Chroot without fork(), FORGET IT!');
		exit(11);
	}
}

pinetd\Logger::log(pinetd\Logger::LOG_DEBUG, 'My name: '.$GLOBALS['CONFIG']->Global->Name);

if ($argc > 1) {
	switch($argv[1]) {
		default:
			echo 'Error : unknown option'."\n";
			exit(1);
	}
}

// before starting core, enable garbage collector
gc_enable();

// if we get here, it means we should start
$sys = new pinetd\Core();

$sys->mainLoop();


