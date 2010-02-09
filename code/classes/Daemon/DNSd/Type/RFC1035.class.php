<?php

namespace Daemon\DNSd\Type;

class RFC1035 extends Base {
	const TYPE_A = 1;
	const TYPE_NS = 2;
	const TYPE_CNAME = 5;
	const TYPE_SOA = 6;
	const TYPE_PTR = 12;
	const TYPE_MX = 15;
	const TYPE_TXT = 16;
	const TYPE_AXFR = 252;
	const TYPE_ANY = 255;
	
	public function decode($val, array $context) {
		switch($this->type) {
			case self::TYPE_A:
				$this->value = inet_ntop($val);
				break;
			case self::TYPE_NS:
				$foo_offset = 0;
				$this->value = $this->pkt->decodeLabel($val, $foo_offset);
				break;
			case self::TYPE_CNAME:
				$foo_offset = 0;
				$this->value = $this->pkt->decodeLabel($val, $foo_offset);
				break;
			case self::TYPE_SOA:
				$this->value = array();
				$offset = 0;
				$this->value['mname'] = $this->pkt->decodeLabel($val, $offset); // master name
				$this->value['rname'] = $this->pkt->decodeLabel($val, $offset); // responsible email
				$next_values = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($val, $offset));
				break;
			case self::TYPE_MX:
				$tmp = unpack('n', $val);
				$this->value = array(
					'priority' => $tmp[0],
					'host' => substr($val, 2),
				);
				break;
			case self::TYPE_TXT:
				$len = ord($val[0]);
				if ((strlen($val) + 1) < $len) {
					$this->value = NULL;
					break;
				}
				$this->value = substr($val, 1, $len);
				break;
			case self::TYPE_AXFR:
				$this->value = NULL;
				break;
			case self::TYPE_ANY:
				$this->value = NULL;
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
			case self::TYPE_A:
				$enc = inet_pton($val);
				if (strlen($enc) != 4) $enc = "\0\0\0\0";
				return $enc;
			case self::TYPE_NS:
				return $this->pkt->encodeLabel($val, $offset);
			case self::TYPE_CNAME:
				return $this->pkt->encodeLabel($val, $offset);
			case self::TYPE_SOA:
				$res = '';
				$res .= $this->pkt->encodeLabel($val['mname'], $offset);
				$res .= $this->pkt->encodeLabel($val['rname'], $offset+strlen($res));
				$res .= pack('NNNNN', $val['serial'], $val['refresh'], $val['retry'], $val['expire'], $val['minimum']);
				return $res;
			case self::TYPE_MX:
				return pack('n', $val['priority']).$this->pkt->encodeLabel($val['host'], $offset+2);
			case self::TYPE_TXT:
				if (strlen($val) > 255) $val = substr($val, 0, 255);
				return chr(strlen($val)) . $val;
			case self::TYPE_AXFR:
				return '';
			case self::TYPE_ANY:
				return '';
			default: // unknown type
				return $val;
		}
	}
}

