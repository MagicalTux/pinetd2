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


namespace Daemon\MREGd;
use pinetd\Timer;
use pinetd\Logger;

class Base extends \pinetd\TCP\Base {
	public function __construct($port, $daemon, &$IPC, $node) {
		// we can do stuff here if we need
		parent::__construct($port, $daemon, $IPC, $node);
	}

	public function _ChildIPC_checkLogin(&$daemon, $login, $random, $sign) {
		return $this->checkLogin($login, $random, $sign);
	}

	public function checkLogin($login, $random, $sign) {
		if ($login != $this->localConfig['MREG']['Login']) return false;
		$key = $this->localConfig['MREG']['Key'];
		if (sha1($random.$key, true) != $sign) return false;

		return true;
	}

	public function mainLoop() {
		parent::mainLoop();
	}
}

