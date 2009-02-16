<?php

namespace pinetd;

class SQL {
	static private $inst = array(); // instances list

	/**
	 * @brief Current process has been forked.
	 * @internal
	 *
	 * This method is called by the child after forking.
	 */
	static public function forked() {
		self::$inst = array();
	}

	/**
	 * @brief Generate an unique instance of a SQL class
	 *
	 * This function will take a "Storage" localConfig entry. Typical call:
	 * $sql = SQL::factory($this->localConfig['Storage']);
	 */
	static public function factory($config) {
		$type_f = null;
		foreach($config as $type => $settings) {
			if ($type != '_') $type_f = $type;
		}
		if (is_null($type_f)) throw new Exception('SQL Factory: no SQL config provided');
		$type = $type_f;
		$settings = $config[$type];
		$key = self::genKey($type, $settings);
		if (isset(self::$inst[$key])) return self::$inst[$key];
		$class = 'pinetd\\SQL\\'.$type;
		self::$inst[$key] = new $class($settings);
		return self::$inst[$key];
	}

	/**
	 * @brief Parent has forked signal, from parent
	 * @internal
	 */
	static public function parentForked() {
		foreach(self::$inst as &$class) $class->reconnect();
	}

	static private function genKey($type, array $cfg) {
		ksort($cfg);
		$sum = $type;
		foreach($cfg as $var => $val) $sum.=':'.$var.'='.$val;
		return md5($sum);
	}
}

/**
 * @brief Class for SQL expressions
 */
class Expr {
	private $value;

	public function __construct($value) {
		$this->value = $value;
	}

	public function __toString() {
		return $value;
	}
}


