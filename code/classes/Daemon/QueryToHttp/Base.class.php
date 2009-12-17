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


namespace Daemon\QueryToHttp;

class Base extends \pinetd\TCP\Base {
	public function __construct($port, $daemon, &$IPC, $node) {
		// we can do stuff here if we need
		parent::__construct($port, $daemon, $IPC, $node);
	}

	public function _ChildIPC_getUrl() {
		return $this->getUrl();
	}

	public function getUrl() {
		return $this->localConfig['Url']['_'];
	}

	public function _ChildIPC_getHeaders() {
		$res = array();
		foreach($this->localConfig['HeadersArray']['Header'] as $h) {
			$res[] = $h['_'];
		}
		return $res;
	}
}

