<?php

namespace pinetd::SQL;


class MySQL {
	private $settings;
	private $mysqli;
	private $DAO = array();

	public function __construct($settings) {
		if (!extension_loaded('mysqli')) throw new Exception('This class requires MySQLi');
		$this->settings = $settings;
		$this->mysqli = mysqli_init();
		$this->mysqli->connect($settings['Host'], $settings['Login'], $settings['Password'], $settings['Database']);
		if (mysqli_connect_errno()) {
			throw new Exception(mysqli_connect_error());
		}
	}

	public function __call($func, $args) {
		return call_user_func_array(array($this->mysqli, $func), $args);
	}

	public function __get($var) {
		return $this->mysqli->$var;
	}

	public function now() {
		return date('Y-m-d H:i:s');
	}

	public function timeStamp($when) {
		return date('Y-m-d H:i:s', $when);
	}

	public function DAO($table, $key) {
		if (!isset($this->DAO[$table])) $this->DAO[$table] = new ::DAO::MySQL($this, $table, $key);
		return $this->DAO[$table];
	}

	// when we fork, this is required
	public function reconnect() {
		$this->mysqli->close();
		$this->mysqli->connect($this->settings['Host'], $this->settings['Login'], $this->settings['Password'], $this->settings['Database']);
		if (mysqli_connect_errno()) {
			throw new Exception(mysqli_connect_error());
		}
	}

	public function quote_escape($string) {
		if (is_null($string)) return 'NULL';
		if (is_array($string)) {
			$res = '';
			foreach($string as $elem) $res .= ($res == ''?'':',') . $this->quote_escape($elem);
			return $res;
		}
		return '\''.$this->mysqli->escape_string($string).'\'';
	}

	function col_gen_type($col) {
		$res = ::strtolower($col['type']);
		switch($res) {
			case 'set': case 'enum':
				$res.='('.$this->quote_escape($col['values']).')';
				break;
			case 'text': case 'blob': case 'datetime':
				break;
			default:
				if (isset($col['size'])) $res.='('.$col['size'].')';
				break;
		}
		if ($col['unsigned']) $res.=' unsigned';
		return $res;
	}

	function gen_field_info($cname, $col) {
		$tmp = '`'.$cname.'` '.$this->col_gen_type($col);
		if (!$col['null']) $tmp.=' NOT NULL';
		if (isset($col['auto_increment'])) $tmp.=' auto_increment';
		if (array_key_exists('default',$col)) $tmp.=' DEFAULT '.$this->quote_escape($col['default']);
		return $tmp;
	}

	function gen_create_query($name, $struct) {
		$req = '';
		$keys = array();
		foreach($struct as $cname=>$col) {
			$req.=($req==''?'':', ').$this->gen_field_info($cname, $col);
			if (isset($col['key'])) $keys[$col['key']][]=$cname;
		}
		foreach($keys as $kname=>$cols) {
			$tmp = '';
			foreach($cols as $c) $tmp.=($tmp==''?'':',').'`'.$c.'`';
			$tmp='('.$tmp.')';
			if ($kname == 'PRIMARY') {
				$tmp = 'PRIMARY KEY '.$tmp;
			} elseif (substr($kname, 0, 7)=='UNIQUE:') {
				$kname = substr($kname, 7);
				$tmp = 'UNIQUE KEY `'.$kname.'` '.$tmp;
			} elseif (substr($kname, 0, 9)=='FULLTEXT:') {
				$kname = substr($kname, 9);
				$tmp = 'FULLTEXT KEY `'.$kname.'` '.$tmp;
			} else {
				$tmp = 'KEY `'.$kname.'` '.$tmp;
			}
			$req.=($req==''?'':',').$tmp;
		}
		$req = 'CREATE TABLE `'.$name.'` ('.$req.') ENGINE=MyISAM DEFAULT CHARSET=utf8';
		return $req;
	}

	public function validateStruct($table_name, $struct) {
		$f = array_flip(array_keys($struct)); // field list
		$req = 'SHOW FIELDS FROM `'.$table_name.'`';
		$res = @$this->mysqli->query($req);
		if (!$res) {
			$req = $this->gen_create_query($table_name, $struct);
			return @$this->mysqli->query($req);
		}
		while($row = $res->fetch_assoc()) {
			if (!isset($f[$row['Field']])) {
				// we got a field we don't know about
				$req = 'ALTER TABLE `'.$table_name.'` DROP `'.$row['Field'].'`';
				@$this->mysqli->query($req);
				continue;
			}
			unset($f[$row['Field']]);
			$col = $struct[$row['Field']];
			if ($row['Type']!=$this->col_gen_type($col)) {
				$req = 'ALTER TABLE `'.$table_name.'` CHANGE `'.$row['Field'].'` '.$this->gen_field_info($row['Field'], $col);
				@$this->mysqli->query($req);
			}
		}
		foreach($f as $k=>$ign) {
			$req = 'ALTER TABLE `'.$table_name.'` ADD '.$this->gen_field_info($k, $struct[$k]);
			@$this->mysqli->query($req);
		}
	}
}

