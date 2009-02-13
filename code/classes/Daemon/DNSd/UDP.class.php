<?php

namespace Daemon\DNSd;

use pinetd\Timer;

class UDP extends \pinetd\UDP\Base {
	private $engine;

	public function __construct($port, $daemon, &$IPC, $node) {
		parent::__construct($port, $daemon, $IPC, $node);

		$class = relativeclass($this, 'Engine');
		$this->engine = new $class($this, $this->IPC, $this->localConfig);
	}

	protected function handlePacket($pkt, $peer) {
		$this->engine->handlePacket($pkt, $peer);
		//Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}

	public function sendReply($pkt, $peer) {
		$this->sendPacket($pkt, $peer);
	}
}

