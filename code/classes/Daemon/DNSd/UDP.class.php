<?php

namespace Daemon\DNSd;
use pinetd\Logger;

class UDP extends \pinetd\UDP\Base {
	protected function handlePacket($pkt, $peer) {
		$pkt = $this->decodePacket($pkt);

		// provide an answer
		$pkt['answer'] = array();
		$pkt['answer'][] = array(
			'name' => $pkt['question'][0]['qname'],
			'type' => $pkt['question'][0]['qtype'],
			'class' => $pkt['question'][0]['qclass'],
			'ttl' => 86400, // 24 hours TTL
			'data' => inet_pton('127.0.0.1'),
		);

		$pkt['authority'] = array();

		$pkt = $this->encodePacket($pkt);
		$this->sendPacket($pkt, $peer);

		Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}

	protected function encodePacket($data) {
		$data['qdcount'] = count($data['question']);
		$data['ancount'] = count($data['answer']);
		$data['nscount'] = count($data['authority']);
		$data['arcount'] = count($data['additional']);
		$pkt = pack('nnnnnn', $data['packet_id'], $data['flags'], $data['qdcount'], $data['ancount'], $data['nscount'], $data['arcount']);

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
			$res .= $this->encodeLabel($rr['name']);
			$res .= pack('nnNn', $rr['type'], $rr['class'], $rr['ttl'], strlen($rr['data'])) . $rr['data'];
		}

		return $res;
	}

	protected function encodeQuestionRR($list) {
		$res = '';

		foreach($list as $rr) {
			// qname, qtype & qclass
			$res .= $this->encodeLabel($rr['qname']);
			$res .= pack('nn', $rr['qtype'], $rr['qclass']);
		}

		return $res;
	}

	protected function encodeLabel($str) {
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

	protected function decodeFlags($flags) {
		$res = array();
		$res['qr'] = $flags >> 15 & 0x1;
		$res['opcode'] = $flags >> 14 & 0xf;
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
			$tmp['data'] = substr($pkt, $offset, $tmp['dlength']);
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

