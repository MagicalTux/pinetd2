<?php

namespace pinetd;

class SQL {
	static private $inst = array(); // instances list

	static public function forked() {
		self::$inst = array();
	}

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
		$class = 'pinetd::SQL::'.$type;
		self::$inst[$key] = new $class($settings);
		return self::$inst[$key];
	}

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


