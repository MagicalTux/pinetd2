<?php

namespace pinetd\Transport;

use pinetd\Logger;

abstract class Base extends \pinetd\DaemonBase {
	protected $id;
	protected $daemon;
	protected $IPC;

	public function __construct($id, $daemon, $IPC, $node) {
		$this->id = $id;
		$this->daemon = $daemon;
		$this->IPC = $IPC;

		$this->loadLocalConfig($node);

		Logger::log(Logger::LOG_INFO, 'Loading transport '.get_class($this));
	}

	protected function initMainLoop() {
		if (defined('PINETD_GOT_PROCTITLE')) {
			setproctitle('Tspt: '.get_class($this));
		}
	}
}


