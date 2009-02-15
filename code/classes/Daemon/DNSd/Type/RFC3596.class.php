<?php
// http://www.ietf.org/rfc/rfc3596.txt

namespace Daemon\DNSd\Type;

class RFC3596 extends Base {
	const TYPE_AAAA = 28;
	
	public function decode($val, array $context) {
		switch($this->type) {
			case self::TYPE_AAAA:
				$this->value = inet_ntop($val);
				break;
			default: // unknown type
				$this->value = $val;
				return false;
		}
		return true;
	}

	public function encode($val = NULL, $offset = NULL) {
		if (is_null($val)) $val = $this->value;
		switch($this->type) {
			case self::TYPE_AAAA:
				$enc = inet_pton($val);
				if (strlen($enc) != 16) $enc = str_repeat("\0", 16);
				return $enc;
			default: // unknown type
				return $val;
		}
	}
}

