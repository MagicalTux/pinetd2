<?php
/*
 * Implementation of EDNS0
 *
 * http://en.wikipedia.org/wiki/EDNS
 * http://tools.ietf.org/html/rfc2671
 */

namespace Daemon\DNSd\Type;

class RFC2671 extends Base {
	const TYPE_OPT = 41;
	
	public function decode($val, array $context) {
		if ($this->type != 41) {
			$this->value = $val;
			return false;
		}
		$ext_rcode = $context['ttl'] >> 24 & 0x8;
	var_dump($this->type, $val, $context);
	// TODO: continue here
		return true;
	}

	public function encode($val = NULL, $offset = NULL) {
		if (is_null($val)) $val = $this->value;
		if ($this->type == 41) return $val;

	}
}

