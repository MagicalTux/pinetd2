<?php

namespace pinetd;

use pinetd\Logger;

abstract class Process extends \pinetd\DaemonBase {
	protected $id;
	protected $daemon;
	protected $IPC;

	public function __construct($id, $daemon, $IPC, $node) {
		$this->id = $id;
		$this->daemon = $daemon;
		$this->IPC = $IPC;

		$this->loadLocalConfig($node);

		Logger::log(Logger::LOG_INFO, 'Loading process '.get_class($this));
	}

	protected function initMainLoop() {
		if (defined('PINETD_GOT_PROCTITLE')) {
			cli_set_process_title('Proc: '.get_class($this));
		}
	}
}


