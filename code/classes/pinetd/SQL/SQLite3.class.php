<?php

namespace pinetd\SQL;

use \Exception;

class SQLite3 {
	private $settings;
	private $sqlite;
	private $unique;
	private $DAO = array();

	public function __construct(array $settings) {
		if (!extension_loaded('sqlite3')) throw new Exception('This class requires SQLite3');
		$this->settings = $settings;
		$this->sqlite = new \SQLite3($settings['File']);
		// TODO: handle errors

		$this->unique = sha1($settings['File']);

		$this->sqlite->busyTimeout(30000);
		$this->sqlite->exec('PRAGMA encoding = "UTF-8"');
		$this->sqlite->exec('PRAGMA legacy_file_format = 0');

		// support for missing SQL functions in sqlite
		$this->sqlite->createFunction('now', array($this, 'now'), 0);
		$this->sqlite->createFunction('unix_timestamp', array($this, 'unixTimestamp'), 1);
	}

	public function __call($func, $args) {
		return call_user_func_array(array($this->sqlite, $func), $args);
	}

	public function __get($var) {
		switch($var) {
			case 'error': return '['.$this->sqlite->lastErrorCode().'] '.$this->sqlite->lastErrorMsg();
			case 'insert_id': return $this->sqlite->lastInsertRowID();
			case 'affected_rows': return $this->sqlite->changes();
		}
		return $this->sqlite->$var;
	}

	public function unique() {
		// return an unique hash based on the connection
		return $this->unique;
	}

	public function now() {
		return date('Y-m-d H:i:s');
	}

	public function unixTimestamp($date) {
		return strtotime($date); // lazy
	}

	public function timeStamp($when) {
		return date('Y-m-d H:i:s', $when);
	}

	public function prepare($query) {
		$stmt = $this->sqlite->prepare($query);
		if (!$stmt) return $stmt;
		return new SQLite3\Stmt($stmt, $query);
	}

	public function insert($table, $data) {
		$fields = '';
		$values = '';
		foreach($data as $var => $val) {
			$fields .= ($fields == ''?'':',').'`'.$var.'`';
			$values .= ($values == ''?'':',').$this->quote_escape($val);
		}

		$req = 'INSERT INTO `'.$table.'` ('.$fields.') VALUES('.$values.')';

		return $this->sqlite->exec($req);
	}

	public function DAO($table, $key) {
		if (!isset($this->DAO[$table])) $this->DAO[$table] = new \DAO\SQLite3($this, $table, $key);
		return $this->DAO[$table];
	}

	public function query($query) {
		$res = @$this->sqlite->query($query);
		if (is_bool($res)) return $res;

		return new SQLite3\Result($res);
	}

	// when we fork, this is required
	public function reconnect() {
		// TODO
	}

	public function quote_escape($string) {
		if (is_null($string)) return 'NULL';
		if ($string instanceof \pinetd\SQL\Expr) return (string)$string;
		if (is_array($string)) {
			$res = '';
			foreach($string as $elem) $res .= ($res == ''?'':',') . $this->quote_escape($elem);
			return $res;
		}
		return '\''.$this->sqlite->escapeString($string).'\'';
	}

	public function escape_string($string) {
		return $this->sqlite->escapeString($string);
	}

	function col_gen_type($col) {
		$res = strtolower($col['type']);
		if ($col['auto_increment']) return 'INTEGER';
		switch($res) {
			case 'set': case 'enum': // not supported by sqlite
				$max_len = 0;
				foreach($col['values'] as $val) $max_len = max($max_len, strlen($val));
				$res='varchar('.$max_len.')';
				break;
			case 'text': case 'blob': case 'datetime': // no size!
				break;
			default:
				if (isset($col['size'])) $res.='('.$col['size'].')';
				break;
		}
		if ($col['unsigned']) $res='unsigned '.$r;
		return $res;
	}

	function gen_field_info($cname, $col) {
		$tmp = '`'.$cname.'` '.$this->col_gen_type($col);
		if ($col['key'] == 'PRIMARY') $tmp .=' PRIMARY KEY';
		if ($col['auto_increment']) $tmp.=' AUTOINCREMENT';
		if (!$col['null']) $tmp.=' NOT NULL';
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
		$req = array('BEGIN TRANSACTION', 'CREATE TABLE `'.$name.'` ('.$req.')');
		foreach($keys as $kname=>$cols) {
			if ($kname == 'PRIMARY') continue;
			$tmp = '';
			foreach($cols as $c) $tmp.=($tmp==''?'':',').'`'.$c.'`';
			$tmp='('.$tmp.')';
			if (substr($kname, 0, 7)=='UNIQUE:') {
				$kname = substr($kname, 7);
				$tmp = 'CREATE UNIQUE INDEX `'.$name.'_'.$kname.'` ON `'.$name.'` '.$tmp;
			} else {
				$tmp = 'CREATE INDEX `'.$name.'_'.$kname.'` ON `'.$name.'` '.$tmp;
			}
			$req[]=$tmp;
		}
		$req[] = 'COMMIT';
		return $req;
	}

	public function validateStruct($table_name, $struct) {
		$f = array_flip(array_keys($struct)); // field list

		// check if table exists
		$res = $this->querySingle('SELECT 1 FROM `sqlite_master` WHERE `type`=\'table\' AND `name` = '.$this->quote_escape($table_name));
		if (is_null($res)) {
			// table does not exists
			$req = $this->gen_create_query($table_name, $struct);
			if (is_array($req)) {
				foreach($req as $query) {
					if (!$this->sqlite->exec($query)) {
						$this->sqlite->exec('ROLLBACK');
						return false;
					}
				}
				return true;
			}
			return $this->sqlite->exec($req);
		}
		return;
		// get structure for this table
		$res = $this->query('PRAGMA table_info('.$table_name.')');
		// TODO: decode "CREATE TABLE" returned by query (string)
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

