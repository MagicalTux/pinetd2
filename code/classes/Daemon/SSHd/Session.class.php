<?php

namespace Daemon\SSHd;

class Session extends Channel {
	private $mode = NULL;

	protected function init($pkt) {
		// nothing to do, in fact :D
	}

	protected function _req_shell() {
		if (!is_null($this->mode)) return false;
		$this->mode = 'shell';
		$this->send('$ ');
		return true; // I am a shell
	}
}

