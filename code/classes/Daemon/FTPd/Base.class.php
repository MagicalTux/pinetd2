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


namespace Daemon\FTPd;
use \pinetd\Logger;

class Base extends \pinetd\TCP\Base {
	//

	public function __construct($port, $daemon, &$IPC, $node) {
		// we can do stuff here if we need
		parent::__construct($port, $daemon, $IPC, $node);
	}

	public function _ChildIPC_getUserCount() {
		return $this->getUserCount();
	}

	public function _ChildIPC_getAnonymousCount() {
		return $this->getAnonymousCount();
	}

	public function _ChildIPC_getAnonymousRoot() {
		return $this->getAnonymousRoot();
	}

	public function _ChildIPC_checkAccess(&$daemon, $login, $pass, $peer) {
		return $this->checkAccess($login, $pass, $peer);
	}

	public function _ChildIPC_setLoggedIn(&$daemon, $fd, $login) {
		$daemon['login'] = $login;
	}

	public function _ChildIPC_getPasvIP() {
		return $this->getPasvIP();
	}

	public function getPasvIP() {
		if (!isset($this->localConfig['Network']['Bind']['Ip']['External'])) return NULL;
		return $this->localConfig['Network']['Bind']['Ip']['External'];
	}

	public function setLoggedIn($fd, $login) {
		$this->clients[$fd]['login'] = $login;
	}

	// NB: Override this function to make your own checkAccess function, and have your own login manager
	public function checkAccess($login, $pass, $peer) {
		// check if login/pass is allowed to connect
		
		$auth = $this->localConfig['Identification'];
		if (!$auth) return false;
		if ( ($login != $auth['Login']) || ($pass != $auth['Password'])) {
			return false; // die!
		}

		Logger::log(Logger::LOG_INFO, 'User '.$login.' logging in from '.$peer[0].':'.$peer[1].' ('.$peer[2].')');

		$res = array(
			'root' => $this->getAnonymousRoot(), // where should we have access
			'chdir' => '/', // pre-emptive chdir, relative to the FTP root
//			'suid_user' => 'nobody', // force suid on login
//			'suid_group' => 'nobody', // force suid on login
			'banner' => array(
				'Welcome to the anonymous root ftp',
			),
		);
		return $res;
	}

	public function getAnonymousRoot() {
		return $this->localConfig['AnonymousRoot']['_'];
	}

	public function getAnonymousCount() {
		$max = $this->localConfig['MaxUsers']['Anonymous'];
		if (substr($max, -1) == '%') {
			$max = substr($max, 0, -1);
			$max = $this->localConfig['MaxUsers']['_'] * $max / 100;
		}
		if ($max == 0) return array(0,0); // disallow login
		$count = 0;
		foreach($this->clients as $info) if ($info['login'] == 'ftp') $count++;
		foreach($this->fclients as $info) if ($info['login'] == 'ftp') $count++;
		return array($count,$max);
	}

	public function getUserCount() {
		$max = $this->localConfig['MaxUsers']['_'];
		$count = count($this->clients) + count($this->fclients);
		return array($count, $max);
	}

	public function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'Client');
		$new = new $class($socket, $peer, $parent, $protocol);

		// check for availability
		$max = $this->localConfig['MaxUsers']['_'];
		if ($max != -1) {
			if (!$max) {
				$new->sendMsg('500 This FTP server is disabled, please try again later.');
				$new->close();
				return false;
			}
			$count = count($this->clients) + count($this->fclients);
			if ($count > $max) {
				$new->sendMsg('500 Too many clients connected, please try again later.');
				$new->close();
				return false;
			}
		}
		return $new;
	}
}

