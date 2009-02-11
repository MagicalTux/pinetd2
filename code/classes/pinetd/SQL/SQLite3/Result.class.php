<?php

namespace pinetd\SQL\SQLite3;

class Result {
	private $result;

	public function __construct(\SQLite3Result $result) {
		$this->result = $result;
	}

	public function fetch_assoc() {
		return $this->fetchArray(SQLITE3_ASSOC);
	}
}

