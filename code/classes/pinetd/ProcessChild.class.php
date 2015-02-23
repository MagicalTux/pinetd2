<?php
namespace pinetd;

use pinetd\Logger;
use pinetd\Timer;

abstract class ProcessChild {
	protected $IPC = null;
	protected $processBaseName = '';

	abstract public function shutdown();
	abstract public function mainLoop($IPC);

	public function __construct($parent) {
		$this->IPC = $parent;
	}

	protected function log($level, $msg) {
		$class = get_class($this);
		$class = explode('\\', $class);
		$daemon = $class[1];
		return Logger::log($level, '['.$daemon.'/'.$this->peer[0].':'.$this->peer[1].'] '.$msg);
	}

	protected function setProcessStatus($status = '') {
		if (!defined('PINETD_GOT_PROCTITLE')) return;
		if ($this->processBaseName) {
			if (!$status) {
				cli_set_process_title($this->processBaseName);
			} else {
				cli_set_process_title($this->processBaseName . ' - ' . $status);
			}
			return;
		}
		if (!$status) {
			cli_set_process_title('(pinetd)');
		} else {
			cli_set_process_title($status);
		}
	}

	protected function processTimers() {
		Timer::processTimers();
	}
}

