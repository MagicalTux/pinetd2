<?php

namespace Daemon::HTTPd;

class Base extends ::pinetd::TCP::Base {
	public function _ChildIPC_getVersionString() {
		return $this->getVersionString();
	}

	public function getVersionString() {
		return get_class($this).'/1.0.0 (pinetd/'.PINETD_VERSION.' PHP/'.PHP_VERSION.')';
	}
}

