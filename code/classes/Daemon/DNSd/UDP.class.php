<?php

// http://en.wikipedia.org/wiki/List_of_DNS_record_types
// http://www.dns.net/dnsrd/rfc/
// http://www.faqs.org/rfcs/rfc1035.html

namespace Daemon\DNSd;
use pinetd\Logger;

class UDP extends \pinetd\UDP\Base {
	private $dns_type = array(
		1     => 'A',          // RFC 1035: Host address
		2     => 'NS',         // RFC 1035: Authoritative Name Server
		5     => 'CNAME',      // RFC 1035: Canonical Name for an alias
		6     => 'SOA',        // RFC 1035: Start Of Authority
		12    => 'PTR',        // RFC 1035: A domain name pointer
		15    => 'MX',         // RFC 1035: Mail eXchange
		16    => 'TXT',        // RFC 1035: Text strings
		18    => 'AFSDB',      // RFC 1183: AFS database record
		24    => 'SIG',        // RFC 2535: Sig(0). See RFC 2931. Deprecated by RFC 3755
		25    => 'KEY',        // RFC 2535: Key record, see RFC 2930.
		28    => 'AAAA',       // RFC 3596: IPv6 address record
		29    => 'LOC',        // RFC 1876: Geographical location
		33    => 'SRV',        // RFC 2782: Service locator
		35    => 'NAPTR',      // RFC 3403: Naming Authority Pointer
		37    => 'CERT',       // RFC 4398
		39    => 'DNAME',      // RFC 2672
		41    => 'OPT',        // RFC 2671
		43    => 'DS',         // RFC 3658: Delegation Signer
		44    => 'SSHFP',      // RFC 4255: SSH Public Key Fingerprint
		45    => 'IPSECKEY',   // RFC 4025
		46    => 'RRSIG',      // RFC 3755
		47    => 'NSEC',       // RFC 3755
		48    => 'DNSKEY',     // RFC 3755
		49    => 'DHCID',      // RFC 4701
		50    => 'NSEC3',      // RFC 5155
		51    => 'NSEC3PARAM', // RFC 5155
		55    => 'HIP',        // RFC 5205: Host Identity Protocol
		99    => 'SPF',        // RFC 4408: SPF Record
		249   => 'TKEY',       // RFC 2930
		250   => 'TSIG',       // RFC 2845
		251   => 'IXFR',       // RFC 1995: Incremental Zone Transfer
		252   => 'AXFR',       // RFC 1035: Transfer of an entire zone
		255   => 'ANY',        // RFC 1035: Request for all records
		32768 => 'TA',         //           DNSSEC Trust Authorities
		32769 => 'DLV',        // RFC 4431: DNSSEC Lookaside Validation Record
	);


	private $dns_type_rfc = array(
		1     => 1035, // RFC 1035: Host address
		2     => 1035, // RFC 1035: Authoritative Name Server
		5     => 1035, // RFC 1035: Canonical Name for an alias
		6     => 1035, // RFC 1035: Start Of Authority
		12    => 1035, // RFC 1035: A domain name pointer
		15    => 1035, // RFC 1035: Mail eXchange
		16    => 1035, // RFC 1035: Text strings
		18    => 1183, // RFC 1183: AFS database record
		24    => 2535, // RFC 2535: Sig(0). See RFC 2931. Deprecated by RFC 3755
		25    => 2535, // RFC 2535: Key record, see RFC 2930.
		28    => 3596, // RFC 3596: IPv6 address record
		29    => 1876, // RFC 1876: Geographical location
		33    => 2782, // RFC 2782: Service locator
		35    => 3403, // RFC 3403: Naming Authority Pointer
		37    => 4398, // RFC 4398
		39    => 2672, // RFC 2672
		41    => 2671, // RFC 2671
		43    => 3658, // RFC 3658: Delegation Signer
		44    => 4255, // RFC 4255: SSH Public Key Fingerprint
		45    => 4025, // RFC 4025
		46    => 3755, // RFC 3755
		47    => 3755, // RFC 3755
		48    => 3755, // RFC 3755
		49    => 4701, // RFC 4701
		50    => 5155, // RFC 5155
		51    => 5155, // RFC 5155
		55    => 5205, // RFC 5205: Host Identity Protocol
		99    => 4408, // RFC 4408: SPF Record
		249   => 2930, // RFC 2930
		250   => 2845, // RFC 2845
		251   => 1995, // RFC 1995: Incremental Zone Transfer
		252   => 1035, // RFC 1035: Transfer of an entire zone
		255   => 1035, // RFC 1035: Request for any kind of record
//		32768 => 'TA',         //           DNSSEC Trust Authorities
		32769 => 4431, // RFC 4431: DNSSEC Lookaside Validation Record
	);

	private $dns_class = array(
		1 => 'IN', // Teh Internet
		2 => 'CS', // The CSNET class (obsolete, used only for examples in some obsolete RFCs)
		3 => 'CH', // The CHAOS class
		4 => 'HS', // Hesiod [Dyer 87]
		255 => 'ANY', // Any class
	);

	protected function handlePacket($pkt, $peer) {
		$pkt = $this->decodePacket($pkt);
		if (!$pkt) return;

		var_dump($pkt);

		// provide an answer
		$pkt['answer'] = array();
		$pkt['answer'][] = array(
			'name' => $pkt['question'][0]['qname'],
			'type' => $pkt['question'][0]['qtype'],
			'class' => $pkt['question'][0]['qclass'],
			'ttl' => 86400, // 24 hours TTL
			'data' => '127.0.0.1',
		);

		$pkt['authority'] = array();

		$pkt['additional'] = array();
		$pkt['additional'][] = array(
			'name' => 'some.test.tld.',
			'type' => 5, // CNAME
			'class' => 1, // IN
			'ttl' => 120,
			'data' => 'some.other.test.',
		);

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

	protected function encodeRRData($rr) {
		switch($rr['type']) {
			case 1: // "A": Host address
				$addr = inet_pton($rr['data']);
				if (strlen($addr) != 4) $addr = "\0\0\0\0";
				return $addr;
			case 2: // "NS": Authoritative Name Server
				return $this->encodeLabel($rr['data']);
			case 3: // "MD": Mail Destination. Obsolete, use MX
				return ''; // TODO
			case 4: // "MF": Mail forwarder. Obsolete, use MX
				return ''; // TODO
			case 5: // "CNAME": Canonical Name for an alias
				return $this->encodeLabel($rr['data']);
			case 6: // "SOA": Start Of Authority
				return ''; // TODO XXX TODO XXX TODO XXX
			case 7: // "MB": Mailbox Domain Name (EXPERIMENTAL)
				return ''; // TODO
			case 8: // "MG": Mail group member (EXPERIMENTAL)
				return ''; // TODO
			case 9: // "MR": A mail rename domain name (EXPERIMENTAL)
				return ''; // TODO
			case 10: // "NULL"
				return '';
			case 11: // "WKS": Well known service description
				return ''; // TODO
			case 12: // "PTR": A domain name pointer
				return ''; // TODO
			case 13: // "HINFO": Host information
				return ''; // TODO
			case 14: // "MINFO": Mailbox or mail list info
				return ''; // TODO
			case 15: // "MX": Mail eXchange info
				return pack('n', $rr['data']['priority']).$this->encodeLabel($rr['data']['host']);
			case 16: // "TXT": text strings
				return $rr['data'];
			case 252: // "AXFR": Transfer of an entire zone
				return ''; // TODO
			case 253: // "MAILB": mailbox related records (MB, MG or MR)
				return ''; // TODO
			case 254: // "MAILA": request for mail agent RRs (obsolete, see MX)
				return ''; // TODO
			case 255: // ANY
				return '';
			default:
				return '';
		}
	}

	protected function encodeRR($list) {
		$res = '';

		foreach($list as $rr) {
			$res .= $this->encodeLabel($rr['name']);
			$data = $this->encodeRRData($rr);
			$res .= pack('nnNn', $rr['type'], $rr['class'], $rr['ttl'], strlen($data)) . $data;
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

	protected function decodeRRData($type, $data) {
		switch($type) {
			case 1: // "A": Host address
				return inet_ntop($data);
			case 2: // "NS": Authoritative Name Server
				return $this->decodeLabel($data, $x = 0);
			case 3: // "MD": Mail Destination. Obsolete, use MX
				return NULL; // TODO
			case 4: // "MF": Mail forwarder. Obsolete, use MX
				return NULL; // TODO
			case 5: // "CNAME": Canonical Name for an alias
				return $this->decodeLabel($data, $x = 0);
			case 6: // "SOA": Start Of Authority
				return NULL; // TODO XXX TODO XXX TODO XXX
			case 7: // "MB": Mailbox Domain Name (EXPERIMENTAL)
				return NULL; // TODO
			case 8: // "MG": Mail group member (EXPERIMENTAL)
				return NULL; // TODO
			case 9: // "MR": A mail rename domain name (EXPERIMENTAL)
				return NULL; // TODO
			case 10: // "NULL"
				return NULL;
			case 11: // "WKS": Well known service description
				return NULL; // TODO
			case 12: // "PTR": A domain name pointer
				return NULL; // TODO
			case 13: // "HINFO": Host information
				return NULL; // TODO
			case 14: // "MINFO": Mailbox or mail list info
				return NULL; // TODO
			case 15: // "MX": Mail eXchange info
				$pri = unpack('n', $data);
				$res = array(
					'priority' => $pri[0],
					'host' => $this->decodeLabel(substr($data, 2), $x = 0),
				);
				return $res;
			case 16: // "TXT": text strings
				return $data;
			case 252: // "AXFR": Transfer of an entire zone
				return NULL; // TODO
			case 253: // "MAILB": mailbox related records (MB, MG or MR)
				return NULL; // TODO
			case 254: // "MAILA": request for mail agent RRs (obsolete, see MX)
				return NULL; // TODO
			case 255: // ANY
				return NULL;
			default:
				return NULL;
		}
	}

	protected function decodeLabel($pkt, &$offset) {
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
			$tmp['data'] = $this->decodeRRData($tmp['type'], substr($pkt, $offset, $tmp['dlength']));
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

