<?php

namespace Daemon::PMaild;

class IMAP4 extends ::pinetd::TCP::Base {
	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = ::relativeclass($this, 'IMAP4_Client');
		return new $class($socket, $peer, $parent, $protocol);
	}
}


