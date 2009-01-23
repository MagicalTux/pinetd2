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
				setproctitle($this->processBaseName);
			} else {
				setproctitle($this->processBaseName . ' - ' . $status);
			}
			return;
		}
		if (!$status) {
			setproctitle('(pinetd)');
		} else {
			setproctitle($status);
		}
	}

	protected function processTimers() {
		Timer::processTimers();
	}
}

