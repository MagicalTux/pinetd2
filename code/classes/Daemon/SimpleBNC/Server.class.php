<?php

namespace Daemon\SimpleBNC;
use pinetd\Logger;

class Server extends \pinetd\TCP\Base {
	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'Server_Thread');
		return new $class($socket, $peer, $parent, $protocol);
	}
}
