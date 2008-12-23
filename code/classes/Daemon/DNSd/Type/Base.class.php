<?php

namespace Daemon\DNSd\Type;

abstract class Base {
	protected $type;
	protected $value;

	abstract public function decode($val);
	abstract public function encode($val = NULL);

	public function __construct($type) {
		$this->type = $type;
	}

	public function setValue($val) {
		$this->value = $val;
	}

	public function getType() {
		return $this->type;
	}

	public static function decodeLabel($pkt, &$offset) {
		$end_offset = NULL;
		$qname = '';
		while(1) {
			$len = ord($pkt[$offset]);
			if (($len >> 14 & 0x2) == 0x2) { // "DNS PACKET COMPRESSION"
				// switch to a different offset, but keep this one as "end of packet"
				$end_offset = $offset+1;
				$offset = $len & 0x3f;
				continue;
			}
			if ($len > (strlen($pkt) - $offset)) return NULL; // ouch! parse error!!
			if ($len == 0) {
				if ($qname == '') $qname = '.';
				++$offset;
				break;
			}
			$qname .= substr($pkt, $offset+1, $len).'.';
			$offset += $len + 1;
		}
		if (!is_null($end_offset)) {
			$offset = $end_offset;
		}

		return $qname;
	}

	public static function encodeLabel($str) {
		// encode a label :)
		$res = '';
		$str = explode('.', $str);
		foreach($str as $bit) {
			$res .= chr(strlen($bit));
			if (strlen($bit) == 0) break;
			$res .= $bit;
		}

		return $res;
	}
}

