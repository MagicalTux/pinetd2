<?php

namespace Daemon\SimpleBNC;
use pinetd\Logger;

class Server extends \pinetd\TCP\Base {
	
	public function __construct() {
		var_dump($this->localConfig);	
	}

	public function mainLoop() {
		
	}

	public function shutdown() {

	}
}
