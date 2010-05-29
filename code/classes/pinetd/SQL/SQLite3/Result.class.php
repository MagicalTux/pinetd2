<?php

namespace pinetd\SQL\SQLite3;

class Result {
	private $result;

	public function __construct(\SQLite3Result $result) {
		$this->result = $result;
	}

	public function fetch_assoc() {
		return $this->result->fetchArray(SQLITE3_ASSOC);
	}

	public function close() {
		return $this->result->finalize();
	}
}

