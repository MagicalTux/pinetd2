<?php

namespace pinetd;
use pinetd\ConfigManager;

use pinetd\Timer;

abstract class DaemonBase {
	protected $localConfig = array();

	/* mainLoop
	 * This function is called only when running with fork, and should never
	 * return.
	 */
	abstract public function mainLoop();
	abstract public function shutdown(); // must close any existing child before returning

	protected function _readLocalConfig(&$node, &$array, $is_subarray = false) {
		$x = 0;
		foreach($node->children() as $var=>$subnode) {
			if ($is_subarray) {
				$this->_readLocalConfig($subnode, $array[$var][], (substr($var, -5) == 'Array'));
			} else {
				$this->_readLocalConfig($subnode, $array[$var], (substr($var, -5) == 'Array'));
			}
		}
		if (!$x) {
			$array['_'] = (string)$node;
		}
		foreach($node->attributes() as $var => $val) {
			$array[$var] = (string)$val;
		}
	}

	protected function loadLocalConfig($node) {
		// fetch config
		$config = ConfigManager::invoke();
		$class = get_class($this);
		$class = explode('\\', $class);
		$daemon = $class[1];
		// load global config
		$this->_readLocalConfig($config->Global, $this->localConfig);
		$this->_readLocalConfig($config->Daemons->$daemon, $this->localConfig);
		$this->_readLocalConfig($node, $this->localConfig);
	}

	public function _ChildIPC_getName() {
		return $this->getName();
	}

	public function getName() {
		return $this->localConfig['Name']['_'];
	}

	public function _ChildIPC_getLocalConfig() {
		return $this->getLocalConfig();
	}

	public function getLocalConfig() {
		return $this->localConfig;
	}

	protected function processTimers() {
		Timer::processTimers();
	}
}

