<?php

namespace Daemon\SimpleBNC;

class Client extends \pinetd\Process {

	protected $connected =	array();

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
		$this->IPC->createPort('SimpleBNC::Transport', $this);
	}

	public function mainLoop() {
		while(1) {
			$this->IPC->selectSockets(200000);
		}
	}

	public function shutdown() {
	}

	public function isConnected($login) {
		if (!array_key_exists($login, $this->connected)) {
			return false;
		}
		return true;
	}

	public function write($login , $raw) {
		if (!$this->isConnected($login)) {
			return false;
		}
		fwrite($this->connection, $raw);
	}
}
