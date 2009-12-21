<?php

namespace Daemon\PMaild;

class POP3 extends \pinetd\TCP\Base {
	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'POP3_Client');
		return new $class($socket, $peer, $parent, $protocol);
	}

	public function _ClientIPC_requireSsl() {
		return $this->requireSsl();
	}

	public function requireSsl() {
		return ($this->localConfig['requireSsl'] == 'yes');
	}
}


