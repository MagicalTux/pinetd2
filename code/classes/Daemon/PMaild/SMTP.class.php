<?php

namespace Daemon\PMaild;

class SMTP extends \pinetd\TCP\Base {
	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'SMTP_Client');
		return new $class($socket, $peer, $parent, $protocol);
	}
}


