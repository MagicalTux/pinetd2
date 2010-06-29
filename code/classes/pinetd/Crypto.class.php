<?php

namespace pinetd;

class Crypto {
	public static function read_cert_req($pem) {
		$blob = self::read_pem($pem, 'CERTIFICATE REQUEST');
		if ($blob === false) return false;
		$data = self::asn1_decode($blob);
		var_dump($data);
	}

	public static function dsa_read_pem($pem, $want_gmp = false) {
		$blob = self::read_pem($pem, 'DSA PRIVATE KEY');
		if ($blob === false) return false;
		$data = self::asn1_decode($blob);
		$keys = array('zero','p','q','g','y','x'); // private is "x"
		$res = array();
		foreach($keys as $idx => $key) {
			if (!isset($data[0][$idx])) return false;
			$val = $data[0][$idx];
			if ($want_gmp) $val = gmp_init(bin2hex($val), 16);
			$res[$key] = $val;
		}
		return $res;
	}

	public static function read_pem($pem, $type) {
		$regexp = '/-----BEGIN '.preg_quote($type)."-----\r?\n(.*?)\r?\n-----END ".preg_quote($type).'-----/s';
		if (!preg_match($regexp, $pem, $match))
			return false;

		return base64_decode($match[1]);
	}

	public static function asn1_decode_len($key, &$pos) {
		$len = ord(substr($key, $pos, 1));
		++$pos;
		if (($len & 0x80) == 0) return $len;
		$len &= 0x7f;
		$final = 0;
		for($i = 0; $i < $len; $i++) {
			$final = ($final << 8) | ord(substr($key, $pos, 1));
			++$pos;
		}
		return $final;
	}
	
	public static function asn1_decode($key) {
		$pos = 0;
		$array = array();
		while($pos < strlen($key)) {
			list(,$code) = unpack('C', substr($key, $pos, 1));
			++$pos;
			switch($code) {
				case 0x01: // BOOLEAN
					$len = self::asn1_decode_len($key, $pos);
					$int = substr($key, $pos, $len);
					$pos += $len;
					$array[] = (bool)ord($int);
					break;
				case 0x02: // INTEGER
				case 0x03: // BIT_STRING
				case 0x06: // IDENTIFIER
				case 0x13: // PRINTABLE_STRING
					$len = self::asn1_decode_len($key, $pos);
					$int = substr($key, $pos, $len);
					$pos += $len;
					$array[] = $int;
					break;
				case 0x05: // NULL
					$array[] = null;
					++$pos;
					break;
				case 0x30: // SEQUENCE
				case 0x31: // SET
				case 0xa0: // OPTIONAL
					$len = self::asn1_decode_len($key, $pos);
					$data = substr($key, $pos, $len);
					$pos += $len;
					$array[] = self::asn1_decode($data);
					break;
				default:
					echo "At $pos/".strlen($key).": ";
					var_dump(dechex($code));
					var_dump(decbin($code));
					echo substr_replace(bin2hex($key), '@', $pos*2, 0)."\n";
					exit;
			}
		}
		return $array;
	}
}

