<?php

namespace Daemon\DNSd;
use pinetd\Logger;

class Base extends \pinetd\UDP\Base {
	protected function handlePacket($pkt, $peer) {
		Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}
}

