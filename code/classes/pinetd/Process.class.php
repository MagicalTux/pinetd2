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
}


