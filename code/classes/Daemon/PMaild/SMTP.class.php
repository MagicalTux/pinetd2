<?php

namespace Daemon\PMaild;

class SMTP extends \pinetd\TCP\Base {
	private $tls_failures = array();

	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'SMTP_Client');
		return new $class($socket, $peer, $parent, $protocol);
	}

	public function _ChildIPC_reportTlsFailure(&$daemon, $ip) {
		$this->reportTlsFailure($ip);
	}

	public function reportTlsFailure($ip) {
		$this->tls_failures[$ip] = time() + 86400;
	}

	public function _ChildIPC_getSmtpConfig() {
		return $this->getSmtpConfig();
	}

	public function getSmtpConfig() {
		return $this->localConfig['SMTP'];
	}

	public function _ChildIPC_isTlsBroken(&$daemon, $ip) {
		return $this->isTlsBroken($ip);
	}

	public function isTlsBroken($ip) {
		if (!isset($this->tls_failures[$ip])) return false;

		if ($this->tls_failures[$ip] < time()) {
			unset($this->tls_failures[$ip]);
			return false;
		}

		return true;
	}
}


