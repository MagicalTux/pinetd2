<?php
namespace pinetd\Transport;

use pinetd\Logger;
use pinetd\Timer;

abstract class Child {
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
				call_user_func_array($this->processBaseName);
			} else {
				call_user_func_array($this->processBaseName . ' - ' . $status);
			}
			return;
		}
		if (!$status) {
			call_user_func_array('(pinetd)');
		} else {
			call_user_func_array($status);
		}
	}

	protected function processTimers() {
		Timer::processTimers();
	}
}

