<?php

namespace pinetd\SQL\SQLite3;
use \SQLite3Stmt;

class Stmt {
	private $stmt;
	private $query;

	public function __construct(SQLite3Stmt $stmt, $query) {
		$this->stmt = $stmt;
		$this->query = $query;
	}

	public function run(array $params = array()) {
		foreach($params as $key => $val)
			$this->stmt->bindValue($key +1, $val, SQLITE3_TEXT);

		$res = $this->stmt->execute();

		if (!is_object($res)) return $res;

		return new Result($res);
	}
}

