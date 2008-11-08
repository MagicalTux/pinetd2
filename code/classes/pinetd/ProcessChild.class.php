<?php
namespace pinetd;

abstract class ProcessChild {
	protected $IPC = null;

	abstract public function shutdown();
	abstract public function mainLoop($IPC);

	public function __construct($parent) {
		$this->IPC = $parent;
	}

	protected function log($level, $msg) {
		$class = get_class($this);
		$class = explode('::', $class);
		$daemon = $class[1];
		return ::pinetd::Logger::log($level, '['.$daemon.'/'.$this->peer[0].':'.$this->peer[1].'] '.$msg);
	}


}

