<?php

namespace Daemon\DNSd\Type;

class RFC2671 extends Base {
	const TYPE_OPT = 41;
	
	public function decode($val) {
		switch($this->type) {
			case self::TYPE_OPT:
				var_dump($val);
				$this->value = NULL;
				break;
			default: // unknown type
				$this->value = $val;
				return false;
		}
		return true;
	}

	public function encode($val = NULL) {
		if (is_null($val)) $val = $this->value;
		switch($this->type) {
			case self::TYPE_OPT:
				return '';
			default: // unknown type
				return $val;
		}
	}
}

