<?php

namespace Daemon\DNSd;

class Packet {
	protected $packet_id = NULL;
	protected $flags = array();

	protected $question = array();
	protected $answer = array();
	protected $authority = array();
	protected $additional = array();

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

		// encode question
		$pkt .= $this->encodeQuestionRR($this->question);
		// encode other fields
		$pkt .= $this->encodeRR($this->answer);
		$pkt .= $this->encodeRR($this->authority);
		$pkt .= $this->encodeRR($this->additional);

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

	protected function encodeRR($list) {
		$res = '';

		foreach($list as $rr) {
			$res .= Type\Base::encodeLabel($rr['name']);
			if (is_object($rr['data'])) {
				$data = $rr['data']->encode();
				$res .= pack('nnNn', $rr['data']->getType(), $rr['class'], $rr['ttl'], strlen($data)) . $data;
			} else {
				$data = Type::encode($rr['type'], $rr['data']);
				$res .= pack('nnNn', $rr['type'], $rr['class'], $rr['ttl'], strlen($data)) . $data;
			}
		}

		return $res;
	}

	protected function encodeQuestionRR($list) {
		$res = '';

		foreach($list as $rr) {
			// qname, qtype & qclass
			$res .= Type\Base::encodeLabel($rr['qname']);
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
		$end_offset = NULL;

		for($i = 0; $i < $count; ++$i) {
			// read qname
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
				$end_offset = NULL;
			}
			// read qtype & qclass
			$tmp = unpack('ntype/nclass/Nttl/ndlength', substr($pkt, $offset, 10));
			$offset += 10;
			$tmp['data'] = Type::decode($tmp['type'], substr($pkt, $offset, $tmp['dlength']));
			$offset += $tmp['dlength'];
			$tmp['name'] = $qname;
			$res[] = $tmp;
		}

		return $res;
	}

	protected function decodeQuestionRR($pkt, &$offset, $count) {
		$res = array();
		$end_offset = NULL;

		for($i = 0; $i < $count; ++$i) {
			// read qname
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
					++$offset;
					break;
				}
				$qname .= substr($pkt, $offset+1, $len).'.';
				$offset += $len + 1;
			}
			if (!is_null($end_offset)) {
				$offset = $end_offset;
				$end_offset = NULL;
			}
			// read qtype & qclass
			$tmp = unpack('nqtype/nqclass', substr($pkt, $offset, 4));
			$offset += 4;
			$tmp['qname'] = $qname;
			$res[] = $tmp;
		}

		return $res;
	}
}

