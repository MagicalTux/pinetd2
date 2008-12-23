<?php

namespace Daemon\DNSd;

use Daemon\DNSd\Type\RFC1035;

class Engine {
	const DNS_CLASS_IN = 1; // Teh Internet
	const DNS_CLASS_CS = 2; // CSNET class (obsolete, used only for examples in some obsolete RFCs)
	const DNS_CLASS_CH = 3; // CHAOS
	const DNS_CLASS_HS = 4; // Hesiod [Dyer 87]

	protected $parent;

	public function __construct($parent) {
		$this->parent = $parent;
	}

	public function handlePacket($pkt, $peer_info) {
		$pkt = $this->decodePacket($pkt);
		if (!$pkt) return;

		$pkt['answer'] = array();
		$pkt['authority'] = array();
		$pkt['additional'] = array();

		foreach($pkt['question'] as $question) {
			if ($question['qclass'] == self::DNS_CLASS_CH) {
				// Special "magical" class...
				if (($question['qname'] == 'version.dnsd.') && ($question['qtype'] == RFC1035::TYPE_TXT)) {
					$txt = Type::factory(RFC1035::TYPE_TXT);
					$txt->setValue('DNSd PHP daemon (PHP/'.PHP_VERSION.')');
					$pkt['answer'][] = array(
						'name' => 'version.dnsd.',
						'class' => self::DNS_CLASS_CH,
						'ttl' => 0,
						'data' => $txt,
					);

					$auth = Type::factory(RFC1035::TYPE_NS);
					$auth->setValue('version.dnsd.');
					$pkt['authority'][] = array(
						'name' => 'version.dnsd.',
						'class' => self::DNS_CLASS_CH,
						'ttl' => 0,
						'data' => $auth,
					);
				}
				continue;
			}

			// provide an answer
			$addr = Type::factory(RFC1035::TYPE_A);
			$addr->setValue('127.0.0.1');
			$pkt['answer'][] = array(
				'name' => $pkt['question'][0]['qname'],
				'type' => $pkt['question'][0]['qtype'],
				'class' => $pkt['question'][0]['qclass'],
				'ttl' => 86400, // 24 hours TTL
				'data' => $addr,
			);

			$pkt['flags']['qr'] = 1;


			$cname = Type::factory(RFC1035::TYPE_TXT);
			$cname->setValue('some.other.test.');
			$pkt['additional'][] = array(
				'name' => 'some.test.tld.',
				'class' => self::DNS_CLASS_IN,
				'ttl' => 120,
				'data' => $cname,
			);

		}

		$pkt['flags']['qr'] = 1;
		$pkt['flags']['aa'] = 1;

		$pkt = $this->encodePacket($pkt);

		if (!is_null($pkt)) $this->parent->sendReply($pkt, $peer_info);
	}

	protected function encodePacket($data) {
		$data['qdcount'] = count($data['question']);
		$data['ancount'] = count($data['answer']);
		$data['nscount'] = count($data['authority']);
		$data['arcount'] = count($data['additional']);
		$pkt = pack('nnnnnn', $data['packet_id'], $this->encodeFlags($data['flags']), $data['qdcount'], $data['ancount'], $data['nscount'], $data['arcount']);

		// encode question
		$pkt .= $this->encodeQuestionRR($data['question']);
		// encode other fields
		$pkt .= $this->encodeRR($data['answer']);
		$pkt .= $this->encodeRR($data['authority']);
		$pkt .= $this->encodeRR($data['additional']);
		return $pkt;
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

	protected function decodePacket($pkt) {
		// unpack packet's header
		$data = unpack('npacket_id/nflags/nqdcount/nancount/nnscount/narcount', $pkt);

		// decode flags
		$data['flags'] = $this->decodeFlags($data['flags']);

		$offset = 12; // just after "question"

		$data['question'] = $this->decodeQuestionRR($pkt, $offset, $data['qdcount']);
		if (is_null($data['question'])) return; // parse error

		$data['answer'] = $this->decodeRR($pkt, $offset, $data['ancount']);
		$data['authority'] = $this->decodeRR($pkt, $offset, $data['nscount']);
		$data['additional'] = $this->decodeRR($pkt, $offset, $data['arcount']);

//		var_dump(bin2hex($pkt), $data);
		return $data;
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

