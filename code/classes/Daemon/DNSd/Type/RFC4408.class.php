<?php
// http://www.ietf.org/rfc/rfc4408.txt
// Type "SPF" works same as type "TXT"

namespace Daemon\DNSd\Type;

class RFC4408 extends Base {
	const TYPE_SPF = 99;
	
	public function decode($val, array $context) {
		switch($this->type) {
			case self::TYPE_SPF:
				$len = ord($val[0]);
				if ((strlen($val) + 1) < $len) {
					$this->value = NULL;
					break;
				}
				$this->value = substr($val, 1, $len);
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
			case self::TYPE_SPF:
				if (strlen($val) > 255) $val = substr($val, 0, 255);
				return chr(strlen($val)) . $val;
			default: // unknown type
				return $val;
		}
	}
}

