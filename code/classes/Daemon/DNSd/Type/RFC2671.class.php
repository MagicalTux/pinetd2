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

	private $flags = array(
		0 => 'do', // DNSSEC enabled
	);

	protected function decodeFlags($flags) {
		$res = array();

		for($i = 0; $i < 16; $i++) {
			if (isset($this->flags[$i])) {
				$name = $this->flags[$i];
			} else {
				$name = 'bit' . $i;
			}

			$res[$name] = (bool)($flags >> (15-$i) & 0x1);
		}

		return $res;
	}

	protected function encodeFlags($flags) {
		$val = 0;
		foreach($this->flags as $bit => $name) {
			if (!$flags[$name]) continue;
			$val |= 1 << (15-$bit);
		}

		for($i = 0; $i < 16; $i++) {
			if (!$flags['bit'.$i]) continue;
			$val |= 1 << (15-$i);
		}

		return $val;
	}
	
	public function decode($val, array $context) {
		if ($this->type != 41) {
			$this->value = $val;
			return false;
		}
		$edns0 = array(
			'ext_code' => $context['ttl'] >> 24 & 0xff,
			'udp_payload_size' => $context['class'], // max size for reply, according to sender
			'version' => $context['ttl'] >> 16 & 0xff,
			'flags' => $this->decodeFlags($context['ttl'] & 0xffff),
		);
		// TODO: handle bad version and send error reply if not 0 (field $edns0['version'])
		$this->value = $edns0;
		// TODO: define this ($this->value) as "packet's edns0 data" by reference
		return true;
	}

	public function encode($val = NULL, $offset = NULL) {
		if (is_null($val)) $val = $this->value;
		if ($this->type != 41) return $val;

		$res = array(
			'class' => $val['udp_payload_size'],
			'ttl' => (($val['ext_code'] & 0xff) << 24) | (($val['version'] & 0xff) << 16) | ($this->encodeFlags($val['flags']) & 0xffff),
			'data' => '', // TODO: encode data
		);

		return $res;
	}
}

