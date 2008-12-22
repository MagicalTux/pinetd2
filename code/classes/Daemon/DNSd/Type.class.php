<?php

// http://en.wikipedia.org/wiki/List_of_DNS_record_types
// http://www.dns.net/dnsrd/rfc/
// http://www.faqs.org/rfcs/rfc1035.html

namespace Daemon\DNSd;

class Type {
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


	private static $dns_type_rfc = array(
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

	public static function factory($type) {
		if (!isset($this->dns_type_rfc[$type])) return NULL; // ?!
		$class = 'Type\\RFC'.$this->dns_type_rfc[$type];
		$obj = new $class($type);
		return $obj;
	}

	public static function decode($type, $data) {
		if (is_object($data)) return $data;
		if (!isset(self::$dns_type_rfc[$type])) return NULL; // ?!
		$class = 'Daemon\\DNSd\\Type\\RFC'.self::$dns_type_rfc[$type];
		$obj = new $class($type);
		$obj->decode($data);
		return $obj;
	}

	public static function encode($type, $data) {
		if (is_object($data)) return $data->encode();
		if (!isset(self::$dns_type_rfc[$type])) return NULL; // ?!
		$class = 'Daemon\\DNSd\\Type\\RFC'.self::$dns_type_rfc[$type];
		$obj = new $class($type);
		return $obj->encode($data);
	}
}

