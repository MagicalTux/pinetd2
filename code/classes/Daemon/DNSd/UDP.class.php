<?php

namespace Daemon\DNSd;

use pinetd\Timer;

class UDP extends \pinetd\UDP\Base {
	private $engine;

	public function mainLoop() {
		// some init...
		$class = relativeclass($this, 'Engine');
		$this->engine = new $class($this);

		// this *never* returns
		parent::mainLoop();
	}

	protected function handlePacket($pkt, $peer) {
		$this->engine->handlePacket($pkt, $peer);
		//Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}

	public function sendReply($pkt, $peer) {
		$this->sendPacket($pkt, $peer);
	}
}

