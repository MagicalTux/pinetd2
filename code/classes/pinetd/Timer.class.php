<?php

namespace pinetd;

class Timer {
	private $_timers = array();
	static $self = NULL;

	protected function instance() {
		if (is_null(self::$self)) self::$self = new self();
		return self::$self;
	}

	private function _processTimers() {
		$now = microtime(true);
		$remove = array();
		foreach($this->_timers as $when => &$info) {
			if ($when > $now) break;
			$remove[] = $when;
			$this->processTimer($info);
		}
		foreach($remove as $t) unset($this->_timers[$t]);
	}

	public function processTimers() {
		self::instance()->_processTimers();
	}
}

