<?php

namespace pinetd\SQL;

class Expr {
	private $value;

	public function __construct($str) {
		$this->value = $str;
	}

	public function __toString() {
		return $this->value;
	}
}

