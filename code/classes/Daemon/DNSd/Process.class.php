<?php

namespace Daemon\DNSd;

class Process extends \pinetd\Process {
	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);


		//var_dump($this->localConfig['PeersArray']['Peer']);
	}

	public function mainLoop() {
		parent::initMainLoop();

		$class = relativeclass($this, 'DbEngine');
		$this->db_engine = new $class($this->localConfig);
		$this->IPC->createPort('DNSd::DbEngine', $this->db_engine);

		while(1) {
			$this->IPC->selectSockets(200000);
		}
	}

	public function shutdown() {
	}
}

