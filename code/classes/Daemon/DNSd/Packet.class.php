<?php

namespace Daemon\DNSd;

class Packet {
	protected $packet_id = NULL;
	protected $flags = array();

	protected $question = array();
	protected $answer = array();
	protected $authority = array();
	protected $additional = array();

	protected $_label_cache = array();

	public function decode($pkt) {
		// unpack packet's header
		$data = unpack('npacket_id/nflags/nqdcount/nancount/nnscount/narcount', $pkt);
		$this->packet_id = $data['packet_id'];

		// decode flags
		$this->flags = $this->decodeFlags($data['flags']);

		$offset = 12; // just after "question"

		$this->question = $this->decodeQuestionRR($pkt, $offset, $data['qdcount']);
		$this->answer = $this->decodeRR($pkt, $offset, $data['ancount']);
		$this->authority = $this->decodeRR($pkt, $offset, $data['nscount']);
		$this->additional = $this->decodeRR($pkt, $offset, $data['arcount']);

		return true;
	}

	public function resetAnswer() {
		$this->answer = array();
		$this->authority = array();
		$this->additional = array();
	}

	public function encode() {
		$qdcount = count($this->question);
		$ancount = count($this->answer);
		$nscount = count($this->authority);
		$arcount = count($this->additional);

		$pkt = pack('nnnnnn', $this->packet_id, $this->encodeFlags($this->flags), $qdcount, $ancount, $nscount, $arcount);

		// Reset label compression cache
		$this->_label_cache = array();

		// encode question
		$pkt .= $this->encodeQuestionRR($this->question, strlen($pkt));
		// encode other fields
		$pkt .= $this->encodeRR($this->answer, strlen($pkt));
		$pkt .= $this->encodeRR($this->authority, strlen($pkt));
		$pkt .= $this->encodeRR($this->additional, strlen($pkt));

		return $pkt;
	}

	public function getQuestions() {
		return $this->question;
	}

	public function addAnswer($name, $value, $ttl = 86400, $class = 1) { // no const here, class 1 = IN
		$this->answer[] = array(
			'name' => $name,
			'class' => $class,
			'ttl' => $ttl,
			'data' => $value
		);
	}

	public function addAuthority($name, $value, $ttl = 86400, $class = 1) { // no const here, class 1 = IN
		$this->authority[] = array(
			'name' => $name,
			'class' => $class,
			'ttl' => $ttl,
			'data' => $value
		);
	}

	public function addAdditional($name, $value, $ttl = 86400, $class = 1) { // no const here, class 1 = IN
		$this->additional[] = array(
			'name' => $name,
			'class' => $class,
			'ttl' => $ttl,
			'data' => $value
		);
	}

	public function setFlag($flag, $value) {
		$this->flags[$flag] = $value;
		return true;
	}

	public function decodeLabel($pkt, &$offset) {
		$end_offset = NULL;
		$qname = '';
		while(1) {
			$len = ord($pkt[$offset]);
			$type = $len >> 6 & 0x2;
			if ($type) {
				switch($type) {
					case 0x2: // "DNS PACKET COMPRESSION", RFC 1035
						// switch to a different offset, but keep this one as "end of packet"
						$new_offset = unpack('noffset', substr($pkt, $offset, 2));
						$end_offset = $offset+1;
						$offset = $new_offset['offset'] & 0x3fff;
						break;
					case 0x1: // Extended label, RFC 2671
						break;
				}
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

	public function encodeLabel($str, $offset = NULL) {
		// encode a label :)
		$res = '';
		$in_offset = 0;

		while(1) {
			$pos = strpos($str, '.', $in_offset);
			if ($pos === false) { // end of string ?!
				return $res . "\0";
			}
			// did we cache?
			if (!is_null($offset)) {
				if (isset($this->_label_cache[strtolower(substr($str, $in_offset))])) {
					$code = (0x3 << 14) | $this->_label_cache[strtolower(substr($str, $in_offset))];
					return $res . pack('n', $code);
				}
				if ($offset < 0x3fff) $this->_label_cache[strtolower(substr($str, $in_offset))] = $offset;
			}
			$res .= chr($pos - $in_offset) . substr($str, $in_offset, $pos - $in_offset);
			$offset += ($pos - $in_offset) + 1;
			$in_offset = $pos + 1;
		}
	}

	protected function encodeRR($list, $offset) {
		$res = '';

		foreach($list as $rr) {
			$lbl = $this->encodeLabel($rr['name'], $offset);
			$res .= $lbl;
			$offset += strlen($lbl);

			if (is_object($rr['data'])) {
				$offset += 10;
				$data = $rr['data']->encode(NULL, $offset);
				$offset += strlen($data);
				$res .= pack('nnNn', $rr['data']->getType(), $rr['class'], $rr['ttl'], strlen($data)) . $data;
			} else {
				$offset += 10;
				$data = Type::encode($this, $rr['type'], $rr['data']);
				$offset += strlen($data);
				$res .= pack('nnNn', $rr['type'], $rr['class'], $rr['ttl'], strlen($data)) . $data;
			}
		}

		return $res;
	}

	protected function encodeQuestionRR($list, $offset) {
		$res = '';

		foreach($list as $rr) {
			// qname, qtype & qclass
			$lbl = $this->encodeLabel($rr['qname'], $offset);
			$offset += strlen($lbl) + 4;
			$res .= $lbl;
			$res .= pack('nn', $rr['qtype'], $rr['qclass']);
		}

		return $res;
	}

	protected function encodeFlags(array $flags) {
		$val = 0;
		$val |= ($flags['qr'] & 0x1) << 15;
		$val |= ($flags['opcode'] & 0xf) << 11;
		$val |= ($flags['aa'] & 0x1) << 10;
		$val |= ($flags['tc'] & 0x1) << 9;
		$val |= ($flags['rd'] & 0x1) << 8;
		$val |= ($flags['ra'] & 0x1) << 7;
		$val |= ($flags['z'] & 0x7) << 4;
		$val |= ($flags['rcode'] & 0xf);
		return $val;
	}

	protected function decodeFlags($flags) {
		$res = array();
		$res['qr'] = $flags >> 15 & 0x1;
		$res['opcode'] = $flags >> 11 & 0xf;
		$res['aa'] = $flags >> 10 & 0x1;
		$res['tc'] = $flags >> 9 & 0x1;
		$res['rd'] = $flags >> 8 & 0x1;
		$res['ra'] = $flags >> 7 & 0x1;
		$res['z'] = $flags >> 4 & 0x7; // Reserved for future use (should be zero)
		$res['rcode'] = $flags & 0xf;
		return $res;
	}

	protected function decodeRR($pkt, &$offset, $count) {
		$res = array();

		for($i = 0; $i < $count; ++$i) {
			// read qname
			$qname = $this->decodeLabel($pkt, $offset);
			// read qtype & qclass
			$tmp = unpack('ntype/nclass/Nttl/ndlength', substr($pkt, $offset, 10));
			$tmp['name'] = $qname;
			$offset += 10;
			$tmp['data'] = Type::decode($this, $tmp, $tmp['type'], substr($pkt, $offset, $tmp['dlength']));
			$offset += $tmp['dlength'];
			$res[] = $tmp;
		}

		return $res;
	}

	protected function decodeQuestionRR($pkt, &$offset, $count) {
		$res = array();

		for($i = 0; $i < $count; ++$i) {
			// read qname
			$qname = $this->decodeLabel($pkt, $offset);
			// read qtype & qclass
			$tmp = unpack('nqtype/nqclass', substr($pkt, $offset, 4));
			$offset += 4;
			$tmp['qname'] = $qname;
			$res[] = $tmp;
		}

		return $res;
	}
}

