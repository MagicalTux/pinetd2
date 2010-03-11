<?php
// http://www.ietf.org/rfc/rfc2782.txt
// Type "SRV"

// <int> <int> <int> <label>
// SRV Priority Weight Port Target

namespace Daemon\DNSd\Type;

class RFC2782 extends Base {
	const TYPE_SRV = 33;
	
	public function decode($val, array $context) {
		switch($this->type) {
			case self::TYPE_SRV:
				$this->value = unpack('npriority/nrefresh/nretry', substr($val, 0, 6));
				$offset = 6;
				$this->value['host'] = $this->pkt->decodeLabel($val, $offset);
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
			case self::TYPE_SRV:
				return pack('nnn', $val['priority'], $val['refresh'], $val['retry']).$this->pkt->encodeLabel($val['host'], $offset+6);
			default: // unknown type
				return $val;
		}
	}
}

