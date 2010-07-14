<?php

namespace pinetd;

class Crypto {
	public static function read_cert_req($pem) {
		$blob = self::read_pem($pem, 'CERTIFICATE REQUEST');
		if ($blob === false) return false;
		$data = self::asn1_decode($blob);
		var_dump($data);
	}

	public static function sign($data, $key, $algo = 'sha1') {
		switch($key['type']) {
			case 'rsa': return static::rsa_sign($data, $key, $algo);
			case 'dsa': return static::dsa_sign($data, $key, $algo);
			default: return false;
		}
	}

	public static function sign_check($signed, $signature, $pk, $algo = 'sha1') {
		switch($pk['type']) {
			case 'rsa': return static::rsa_sign_check($signed, $signature, $pk, $algo);
			case 'dsa': return static::dsa_sign_check($signed, $signature, $pk, $algo);
			default: return false;
		}
	}

	public static function dsa_sign($data, $key, $algo = 'sha1') {
		// DSA signature as of csrc.nist.gov/publications/fips/archive/fips186-2/fips186-2.pdf
		if (!isset($key['x'])) return false; // not a private key
		$H = gmp_init($algo($data), 16);
		$bytes_len = strlen(gmp_strval($key['q'], 16))/2;
		while(1) {
			$k = gmp_init(bin2hex(openssl_random_pseudo_bytes($bytes_len)), 16);
			$k = gmp_mod($k, $key['q']);
			$r = gmp_mod(gmp_powm($key['g'], $k, $key['p']), $key['q']);
			if (gmp_cmp($r, 0) == 0) continue;
			$s = gmp_mod(gmp_mul(gmp_invert($k, $key['q']), gmp_add($H, gmp_mul($key['x'], $r))), $key['q']);
			if (gmp_cmp($s, 0) == 0) continue;
			break;
		}
		return static::gmp_binval($r, false).static::gmp_binval($s, false);
	}

	public static function rsa_sign($data, $key, $algo = 'sha1') {
		// RSA signature RFC3447
		if (!isset($key['d'])) return false; // not a private key
		$d = $key['d'];
		$n = $key['n'];

		// generate signature
		$emLen = strlen(gmp_strval($n, 16))/2;

		// TODO: We do not support anything else than sha1 at this time

		// EMSA-PKCS1-v1_5 encoding of our own hash
		$t = pack('H*', '3021300906052b0e03021a05000414'); // RFC 3447 page 43 - means "SHA-1" in DER encoding
		$t .= $algo($data, true);
		$ps = str_repeat("\xff", $emLen-strlen($t)-3); // emLen - tLen - 3
		$m = "\x00\x01".$ps."\x00".$t; // EM = 0x00 || 0x01 || PS || 0x00 || T
		$m = gmp_init(bin2hex($m), 16);
		$s = gmp_powm($m, $d, $n);

		return static::gmp_binval($s, false);
	}

	public static function rsa_sign_check($signed, $signature, $pk, $algo = 'sha1') {
		// RSA signature RFC3447
		$e = $pk['e'];
		$n = $pk['n'];

		$s = gmp_init(bin2hex($signature), 16);
		if (gmp_cmp($s, gmp_sub($n, 1)) > 0) return false;

		// decode signature
		$m = gmp_powm($s, $e, $n);
		$m_bin = "\0".static::gmp_binval($m); // starts with 0x00 0x01, but gmp drops the first 0x00
		$emLen = strlen($m_bin);

		// TODO: instead of receiving $algo as parameter we should parse $m_bin to find out which algo it uses
		// TODO: We do not support anything else than sha1 at this time anyway

		// EMSA-PKCS1-v1_5 encoding of our own hash
		$t = pack('H*', '3021300906052b0e03021a05000414'); // RFC 3447 page 43 - means "SHA-1" in DER encoding
		$t .= $algo($signed, true);
		$ps = str_repeat("\xff", $emLen-strlen($t)-3); // emLen - tLen - 3
		$em = "\x00\x01".$ps."\x00".$t; // EM = 0x00 || 0x01 || PS || 0x00 || T

		return ($m_bin == $em);
	}

	public static function dsa_sign_check($signed, $signature, $pk, $algo = 'sha1') {
		// DSA signature check as of csrc.nist.gov/publications/fips/archive/fips186-2/fips186-2.pdf
		$signed = $algo($signed);
		$len = strlen($signed)/2; // hexa
		if (strlen($signature) != $len*2) return false; // length of hash output * 2
		$r_bin = substr($signature, 0, $len);
		$s_bin = substr($signature, $len, $len);
		$p = $pk['p'];
		$q = $pk['q'];
		$g = $pk['g'];
		$y = $pk['y'];
		$r = gmp_init(bin2hex($r_bin), 16);
		$s = gmp_init(bin2hex($s_bin), 16);
		$M = gmp_init($signed, 16);

		if (gmp_cmp($r, $q) > 0) return false;
		if (gmp_cmp($s, $q) > 0) return false;

		$w = gmp_invert($s, $q);
		$u1 = gmp_mod(gmp_mul($M, $w), $q);
		$u2 = gmp_mod(gmp_mul($r, $w), $q);
		$v = gmp_mod(gmp_mod(gmp_mul(gmp_powm($g, $u1, $p), gmp_powm($y, $u2, $p)), $p), $q);

		return (gmp_cmp($v, $r) == 0);
	}

	/**
	 * @brief Generates a new RSA key and returns the PEM-formatted string
	 */
	public static function rsa_new_pem($bits = 2048) {
		$k=openssl_pkey_new(array("private_key_type"=>OPENSSL_KEYTYPE_RSA,"private_key_bits"=>$bits));
		openssl_pkey_export($k,$pk);
		return $pk; // -----BEGIN RSA PRIVATE KEY----- ... -----END RSA PRIVATE KEY-----
	}

	/**
	 * @brief Generates a new DSA key and returns the PEM-formatted string
	 */
	public static function dsa_new_pem($bits = 1024) {
		$k=openssl_pkey_new(array("private_key_type"=>OPENSSL_KEYTYPE_DSA,"private_key_bits"=>$bits));
		openssl_pkey_export($k,$pk);
		return $pk; // -----BEGIN DSA PRIVATE KEY----- ... -----END DSA PRIVATE KEY-----
	}

	protected static function _ssh_parseStr(&$pkt) {
		list(,$len) = unpack('N', substr($pkt, 0, 4));
		if ($len == 0) {
			$pkt = substr($pkt, 4);
			return '';
		}
		if ($len+4 > strlen($pkt)) return false;
		$res = substr($pkt, 4, $len);
		$pkt = substr($pkt, $len+4);
		return $res;
	}

	protected static function _ssh_str($str) {
		return pack('N', strlen($str)).$str;
	}

	/**
	 * @brief Parse a public key encoded as SSH binary blob
	 */
	public static function read_ssh_pk($bin) {
		$type_list = array(
			'ssh-rsa' => array('rsa', array('e','n')),
			'ssh-dss' => array('dsa', array('p','q','g','y')),
		);
		$type = self::_ssh_parseStr($bin);
		if (!isset($type_list[$type])) return false;
		$res = array('ssh_type' => $type);
		$type = $type_list[$type];
		$res['type'] = $type[0];
		foreach($type[1] as $k) {
			if ($bin === '') return false;
			$t = self::_ssh_parseStr($bin);
			if ($t === false) return false;
			$res[$k] = gmp_init(bin2hex($t), 16);
		}
		return $res;
	}

	public static function make_ssh_pk($key) {
		$type_list = array(
			'rsa' => array('ssh-rsa', array('e','n')),
			'dsa' => array('ssh-dss', array('p','q','g','y')),
		);
		if (!isset($type_list[$key['type']])) return false;
		$type = $type_list[$key['type']];
		$res = self::_ssh_str($type[0]);
		foreach($type[1] as $v) {
			$res .= self::_ssh_str(self::gmp_binval($key[$v]));
		}
		return $res;
	}

	public static function rsa_read_pem($pem) {
		$blob = self::read_pem($pem, 'RSA PRIVATE KEY');
		if ($blob === false) return false;
		$data = self::asn1_decode($blob);
		$keys = array('zero','n','e','d','p','q','exponent1','exponent2','coefficient');
		$res = array();
		foreach($keys as $idx => $key) {
			if (!isset($data[0][$idx])) return false;
			$val = $data[0][$idx];
			$val = gmp_init(bin2hex($val), 16);
			$res[$key] = $val;
		}
		$res['type'] = 'rsa';
		return $res;
	}

	public static function dsa_read_pem($pem) {
		$blob = self::read_pem($pem, 'DSA PRIVATE KEY');
		if ($blob === false) return false;
		$data = self::asn1_decode($blob);
		$keys = array('zero','p','q','g','y','x'); // private is "x"
		$res = array();
		foreach($keys as $idx => $key) {
			if (!isset($data[0][$idx])) return false;
			$val = $data[0][$idx];
			$val = gmp_init(bin2hex($val), 16);
			$res[$key] = $val;
		}
		$res['type'] = 'dsa';
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

	public static function hmac_algos() {
		if (function_exists('hash_algos')) return hash_algos();
		return array('sha1','md5');
	}

	// hmac implementation in userland with fallback to hash_hmac
	public static function hmac($algo, $data, $key, $raw_output = false) {
		if (function_exists('hash_hmac')) return hash_hmac($algo, $data, $key, $raw_output);
		$size = 64;
		$opad = str_repeat(chr(0x5C), $size);
		$ipad = str_repeat(chr(0x36), $size);
		if (strlen($key) > $size) {
			$key = str_pad($algo($key, true), $size, chr(0x00));
		} else {
			$key = str_pad($key, $size, chr(0x00));
		}
		for ($i = 0; $i < strlen($key) - 1; $i++) {
			$opad[$i] = $opad[$i] ^ $key[$i];
			$ipad[$i] = $ipad[$i] ^ $key[$i];
		}
		return $algo($opad . $algo($ipad . $data, true), $raw_output);
	}

	public static function gmp_binval($g, $fix_unsigned = true) {
		$hex = gmp_strval($g, 16);
		if (strlen($hex) & 1) $hex = '0'.$hex;
		$res = pack('H*', $hex);
		if (($fix_unsigned) && (ord($res[0]) & 0x80)) $res = "\0".$res;
		return $res;
	}

	public static function dh_group($name) {
		switch($name) {
			case 'oakley-group-1': // 768-bits
				$p = gmp_init(
					'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'.
					'020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'.
					'4FE1356D6D51C245E485B576625E7EC6F44C42E9A63A3620FFFFFFFFFFFFFFFF', 16);
				return array('p' => $p, 'g' => 2, 'b' => 768);
			case 'oakley-group-2': // 1024-bits
				$p = gmp_init(
					'ffffffffffffffffc90fdaa22168c234c4c6628b80dc1cd129024e088a67cc74'.
					'020bbea63b139b22514a08798e3404ddef9519b3cd3a431b302b0a6df25f1437'.
					'4fe1356d6d51c245e485b576625e7ec6f44c42e9a637ed6b0bff5cb6f406b7ed'.
					'ee386bfb5a899fa5ae9f24117c4b1fe649286651ece65381ffffffffffffffff', 16);
				return array('p' => $p, 'g' => 2, 'b' => 1024);
			case 'oakley-group-5': // 1536-bits
				$p = gmp_init(
					'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'.
					'020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'.
					'4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'.
					'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05'.
					'98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB'.
					'9ED529077096966D670C354E4ABC9804F1746C08CA237327FFFFFFFFFFFFFFFF', 16);
				return array('p' => $p, 'g' => 2, 'b' => 1536);
			case 'oakley-group-14': // 2048-bits
				$p = gmp_init(
					'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'.
					'020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'.
					'4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'.
					'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05'.
					'98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB'.
					'9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B'.
					'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF695581718'.
					'3995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF', 16);
				return array('p' => $p, 'g' => 2, 'b' => 2048);
			case 'oakley-group-15': // 3072-bits
				$p = gmp_init(
					'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'.
					'020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'.
					'4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'.
					'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05'.
					'98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB'.
					'9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B'.
					'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF695581718'.
					'3995497CEA956AE515D2261898FA051015728E5A8AAAC42DAD33170D04507A33'.
					'A85521ABDF1CBA64ECFB850458DBEF0A8AEA71575D060C7DB3970F85A6E1E4C7'.
					'ABF5AE8CDB0933D71E8C94E04A25619DCEE3D2261AD2EE6BF12FFA06D98A0864'.
					'D87602733EC86A64521F2B18177B200CBBE117577A615D6C770988C0BAD946E2'.
					'08E24FA074E5AB3143DB5BFCE0FD108E4B82D120A93AD2CAFFFFFFFFFFFFFFFF', 16);
				return array('p' => $p, 'g' => 2, 'b' => 3072);
		}
		return false;
	}

	public static function dh_init($name) {
		if (!is_array($name)) $name = static::dh_group($name);
		if (!is_array($name)) return false;

		$dh = $name;

		$x_bin = openssl_random_pseudo_bytes($dh['b']/8/2);
		$x = gmp_init(bin2hex($x_bin), 16);
		$e = gmp_powm($dh['g'], $x, $dh['p']);

		return array('x' => $x, 'e' => $e); // keep x, send e
	}

	public static function dh_reply($name, $e) { // e, as we received it
		if (!is_array($name)) $name = static::dh_group($name);
		if (!is_array($name)) return false;

		$dh = $name;

		$y_bin = openssl_random_pseudo_bytes($dh['b']/8/2);
		$y = gmp_init(bin2hex($y_bin), 16);
		// compute $f and $K
		$f = gmp_powm($dh['g'], $y, $dh['p']);
		$K = gmp_powm($e, $y, $dh['p']);

		return array('f' => $f, 'K' => $K); // K is our private key, send f
	}

	public static function dh_finish($name, $init, $f) {
		if (!is_array($name)) $name = static::dh_group($name);
		if (!is_array($name)) return false;

		$dh = $name;

		// K = f^x mod p
		$K = gmp_powm($f, $init['x'], $dh['p']);

		return array('K' => $K); // K is our private key
	}
}

