<?php

namespace pinetd;

use \SplPriorityQueue;

class TimerQueue extends SplPriorityQueue {
	public function compare($priority1, $priority2) {
		// Overload compare func to make it work as we expect with stamps
		if ($priority1 == $priority2) return 0;
		return ($priority1 < $priority2) ? 1 : -1;
	}
}

class Timer {
	private $_timers;
	static $self = NULL;

	private function __construct() {
		$this->_timers = new TimerQueue();
	}

	private static function instance() {
		if (is_null(self::$self)) self::$self = new self();
		return self::$self;
	}

	private function _addTimer(array $timer) {
		$next_run = microtime(true) + $timer['delay'];
		$this->_timers->insert($timer, $next_run);
	}

	public static function addTimer($callback, $delay, &$extra = null, $recurring = false) {
		$timer = array(
			'callback' => $callback,
			'delay' => $delay,
			'extra' => &$extra,
			'recurring' => $recurring,
		);
		self::instance()->_addTimer($timer);
	}

	private function _processTimer($timer) {
		$res = call_user_func($timer['callback'], &$timer['extra']);
		if (($timer['recurring']) && ($res))
			$this->_addTimer($timer);
	}

	private function _processTimers() {
		if (!$this->_timers->valid()) return;

		$now = microtime(true);
		$this->_timers->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
		while(($this->_timers->valid()) && ($this->_timers->top() < $now)) {
			$this->_timers->setExtractFlags(SplPriorityQueue::EXTR_DATA);
			$this->_processTimer($this->_timers->extract());
			$this->_timers->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
		}
	}

	public static function processTimers() {
		self::instance()->_processTimers();
	}

	private function _reset() {
		$this->_timers = new TimerQueue();
	}

	public static function reset() {
		self::instance()->_reset();
	}
}

