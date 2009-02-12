<?php

namespace Daemon\DNSd;

use pinetd\SQL;

class Process extends \pinetd\Process {
	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);

		// check table struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);

		$storage = relativeclass($this, 'Storage');
		$storage::validateTables($this->sql);

		var_dump($this->localConfig['UpdateSignature']['_']);
	}

	public function mainLoop() {
		parent::initMainLoop();
		while(1) {
			$this->IPC->selectSockets(200000);
		}
	}

	public function shutdown() {
	}
}

