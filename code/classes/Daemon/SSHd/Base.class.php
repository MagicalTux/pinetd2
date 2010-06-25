<?php

namespace Daemon\SSHd;

class Base extends \pinetd\TCP\Base {
	public function _ChildIPC_checkAccess(&$daemon, $login, $pass, $peer, $service) {
		return $this->checkAccess($login, $pass, $peer, $service);
	}

	public function checkAccess($login, $pass, $peer, $service) {
		if ($pass == '') return false; // do not allow empty password
		return array('login' => $login); // always OK
	}
}

