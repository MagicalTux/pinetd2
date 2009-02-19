<?php

namespace Daemon\HTTPd;

class Base extends \pinetd\TCP\Base {
	protected $sessions = array();

	public function _ChildIPC_getVersionString() {
		return $this->getVersionString();
	}

	public function getVersionString() {
		return get_class($this).'/1.0.0 (pinetd/'.PINETD_VERSION.' PHP/'.PHP_VERSION.')';
	}

	public function _ChildIPC_getSession(&$daemon, $sessid) {
		return $this->getSession($sessid);
	}

	public function getSession($sessid) {
		if (!isset($this->sessions[$sessid])) return NULL;
		return $this->sessions[$sessid];
	}

	public function _ChildIPC_createSession(&$daemon) {
		return $this->createSession();
	}

	public function createSession() {
		while(1) {
			// TODO: use uuid
			$sid = md5(microtime());
			if (!isset($this->sessions[$sid])) {
				$this->sessions[$sid] = serialize(array());
				return $sid;
			}
		}
	}

	public function _ChildIPC_setSession(&$daemon, $sessid, $val) {
		return $this->setSession($sessid, $val);
	}

	public function setSession($sessid, $val) {
		if (!isset($this->sessions[$sessid])) return false;
		$this->sessions[$sessid] = $val;
		return true;
	}
}

