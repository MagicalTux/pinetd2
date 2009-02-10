<?php

namespace pinetd;
use \Exception;

class SUID {
	private $uid;
	private $gid;

	function __construct($uid, $gid = null) {
		if (is_numeric($uid)) {
			$info = posix_getpwuid($uid);
		} else {
			$info = posix_getpwnam($uid);
		}
		if (!$info) throw new Exception('SUID: uid '.$uid.' not found on system, please check config');
		$this->uid = $info['uid'];
		$this->gid = $info['gid'];
		// do we have gid?
		if (!is_null($gid)) {
			if(is_numeric($gid)) {
				$info = posix_getgrgid($gid);
			} else {
				$info = posix_getgrnam($gid);
			}
			if (!$info) throw new Exception('SUID: Group provided, but can\'t find it on system, please check config');
			$this->gid = $info['gid'];
		}
	}

	function setIt() {
		if (!posix_setgid($this->gid)) return false;
		if (!posix_setuid($this->uid)) return false;
		return true;
	}
}

