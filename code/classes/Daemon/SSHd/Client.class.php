<?php

namespace Daemon\SSHd;
use pinetd\Logger;

class Client extends \pinetd\TCP\Client {
	private $state;
	private $agent;
	private $clearbuf = '';
	private $capa;
	private $payloads = array();
	private $skey;

	private $cipher = array(); // encoding type

	const SSH_MSG_DISCONNECT = 1;
	const SSH_MSG_IGNORE = 2;
	const SSH_MSG_UNIMPLEMENTED = 3;
	const SSH_MSG_DEBUG = 4;
	const SSH_MSG_SERVICE_REQUEST = 5;
	const SSH_MSG_SERVICE_ACCEPT = 6;
	const SSH_MSG_KEXINIT = 20;
	const SSH_MSG_NEWKEYS = 21;
	const SSH_MSG_KEXDH_INIT = 30;
	const SSH_MSG_KEXDH_REPLY = 31;

	function welcomeUser() {
		$this->state = 'new';
		$this->cipher['send'] = array('name' => 'NULL', 'block_size' => 1);
		$this->cipher['recv'] = array('name' => 'NULL', 'block_size' => 1);
		$this->setMsgEnd('');
		$this->sendMsg("Please wait while resolving your hostname...\r\n");
		return true;
	}

	public function sendBanner() {
		$this->sendMsg("SSH-2.0-pinetd PHP/".phpversion()."\r\n");
		$this->payloads['V_S'] = "SSH-2.0-pinetd PHP/".phpversion();
	}

	protected function handlePkt($pkt) {
		switch(ord($pkt[0])) {
			case self::SSH_MSG_IGNORE:
				// fake traffic to keep cnx alive and confuse listeners
				return;
			case self::SSH_MSG_KEXINIT:
				$this->payloads['I_C'] = $pkt;
				$data = array();
				$data['cookie'] = bin2hex(substr($pkt, 1, 16));
				$pkt = substr($pkt, 17); // remove packet code (1 byte) & cookie (16 bytes)
				$list_list = array('kex_algorithms','server_host_key_algorithms','encryption_algorithms_client_to_server','encryption_algorithms_server_to_client','mac_algorithms_client_to_server','mac_algorithms_server_to_client','compression_algorithms_client_to_server','compression_algorithms_server_to_client','languages_client_to_server','languages_server_to_client');
				foreach($list_list as $list) {
					$res = $this->parseNameList($pkt);
					if ($res === false) {
						$this->close();
						return;
					}
					$data[$list] = $res;
				}
				$data['first_kex_packet_follows'] = ord($pkt[0]);
				$data['reserved'] = bin2hex(substr($pkt, 1));
				$this->capa = $data;
				$this->ssh_determineEncryption();
				break;
			case self::SSH_MSG_NEWKEYS:
				// initialize encryption [recv/send]
				$this->sendPacket(chr(self::SSH_MSG_NEWKEYS)); // send before we enable send encryption
				break;
			case self::SSH_MSG_KEXDH_INIT:
				// secure key exchange - diffie-hellman-group1-sha1
				if (!$this->loadSkey()) {
					Logger::log(Logger::LOG_WARN, 'Could not load server key');
					$this->close();
					break;
				}
				// RFC 4253 page 21: 8. Diffie-Hellman Key Exchange
				$p = gmp_init('179769313486231590770839156793787453197860296048756011706444423684197180216158519368947833795864925541502180565485980503646440548199239100050792877003355816639229553136239076508735759914822574862575007425302077447712589550957937778424442426617334727629299387668709205606050270810842907692932019128194467627007');
				list(,$len) = unpack('N', substr($pkt, 1, 4));
				$e_bin = substr($pkt, 5, $len);
				$e = gmp_init(bin2hex($e_bin), 16); // not really optimized but works
				$y = gmp_init(bin2hex(openssl_random_pseudo_bytes(64)), 16);
				$f = gmp_powm(2, $y, $p);
				$f_bin = pack('H*', gmp_strval($f, 16));
				if (ord($f_bin[0]) & 0x80) $f_bin = "\0" . $f_bin;
				$K = gmp_powm($e, $y, $p);
				$K_bin = pack('H*', gmp_strval($K, 16));
				if (ord($K_bin[0]) & 0x80) $K_bin = "\0" . $K_bin;

				// store shared secret in session
				$this->capa['K'] = $K_bin;

				$pub = $this->skey['pub'];

				// H = hash(V_C || V_S || I_C || I_S || K_S || e || f || K)
				$sha = array(
					$this->str($this->payloads['V_C']),
					$this->str($this->payloads['V_S']),
					$this->str($this->payloads['I_C']),
					$this->str($this->payloads['I_S']),
					$this->str($pub),
					$this->str($e_bin),
					$this->str($f_bin),
					$this->str($K_bin)
				);
				$sha = implode('', $sha);
				$H = sha1($sha, true);
				// sign $H
				if (!openssl_sign($H, $s, $this->skey['pkeyid'])) {
					Logger::log(Logger::LOG_WARN, 'Could not sign exchange key');
					$this->close();
					break;
				}

				$s = $this->str($this->skey['type']).$this->str($s);

				$pkt = chr(self::SSH_MSG_KEXDH_REPLY);
				$pkt .= $this->str($pub);
				$pkt .= $this->str($f_bin);
				$pkt .= $this->str($s);
				$this->sendPacket($pkt);
				break;
			default:
				echo "Unknown packet: ".bin2hex($pkt)."\n";
				$this->close(); // TODO: send SSH_MSG_UNIMPLEMENTED instead?
		}
	}

	protected function ssh_determineEncryption() {
		$my_kex_alg = array_flip($this->getKeyExchAlgList());
		$my_key_alg = array_flip($this->getPKAlgList());
		$my_cipher = array_flip($this->getCipherAlgList());
		$my_hmac = array_flip($this->getHmacAlgList());
		$my_comp = array_flip($this->getCompAlgList());

		$fallback_cnt = 0;

		$kex = null;
		foreach($this->capa['kex_algorithms'] as $alg) {
			if (isset($my_kex_alg[$alg])) { $kex = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($kex)) { $this->close(); return; }
		$key_alg = null;
		foreach($this->capa['server_host_key_algorithms'] as $alg) {
			if (isset($my_key_alg[$alg])) { $key_alg = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($key_alg)) { $this->close(); return; }
		$cipher_send = null;
		foreach($this->capa['encryption_algorithms_server_to_client'] as $alg) {
			if (isset($my_cipher[$alg])) { $cipher_send = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($cipher_send)) { $this->close(); return; }
		$cipher_recv = null;
		foreach($this->capa['encryption_algorithms_client_to_server'] as $alg) {
			if (isset($my_cipher[$alg])) { $cipher_recv = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($cipher_recv)) { $this->close(); return; }
		$hmac_send = null;
		foreach($this->capa['mac_algorithms_server_to_client'] as $alg) {
			if (isset($my_hmac[$alg])) { $hmac_send = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($hmac_send)) { $this->close(); return; }
		$hmac_recv = null;
		foreach($this->capa['mac_algorithms_client_to_server'] as $alg) {
			if (isset($my_hmac[$alg])) { $hmac_recv = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($hmac_recv)) { $this->close(); return; }
		$comp_send = null;
		foreach($this->capa['compression_algorithms_server_to_client'] as $alg) {
			if (isset($my_comp[$alg])) { $comp_send = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($comp_send)) { $this->close(); return; }
		$comp_recv = null;
		foreach($this->capa['compression_algorithms_client_to_server'] as $alg) {
			if (isset($my_comp[$alg])) { $comp_recv = $alg; break; }
			$fallback_cnt++;
		}
		if (is_null($comp_recv)) { $this->close(); return; }

		$this->capa['kex'] = $kex;
		$this->capa['key_alg'] = $key_alg;
		$this->capa['cipher_send'] = $cipher_send;
		$this->capa['cipher_recv'] = $cipher_recv;
		$this->capa['hmac_send'] = $hmac_send;
		$this->capa['hmac_recv'] = $hmac_recv;
		$this->capa['comp_send'] = $comp_send;
		$this->capa['comp_recv'] = $comp_recv;
		$this->capa['fallback_cnt'] = $fallback_cnt; // if not 0, means the client didn't guess right
	}

	protected function ssh_sendAlgorithmNegotiationPacket() {
		$pkt = pack('CNNNN', self::SSH_MSG_KEXINIT, mt_rand(0,0xffffffff), mt_rand(0,0xffffffff), mt_rand(0,0xffffffff), mt_rand(0,0xffffffff));
		$pkt .= $this->nameList($this->getKeyExchAlgList());
		$pkt .= $this->nameList($this->getPKAlgList());
		$pkt .= $this->nameList($this->getCipherAlgList());
		$pkt .= $this->nameList($this->getCipherAlgList());
		$pkt .= $this->nameList($this->getHmacAlgList());
		$pkt .= $this->nameList($this->getHmacAlgList());
		$pkt .= $this->nameList($this->getCompAlgList());
		$pkt .= $this->nameList($this->getCompAlgList());
		$pkt .= str_repeat("\0", 8); // no languages_client_to_server / languages_server_to_client
		$pkt .= "\0"; // boolean first_kex_packet_follows
		$pkt .= str_repeat("\0", 4);
		$this->payloads['I_S'] = $pkt;
		$this->sendPacket($pkt);
	}

	protected function parseNameList(&$pkt) {
		list(,$len) = unpack('N', substr($pkt, 0, 4));
		if ($len == 0) {
			$pkt = substr($pkt, 4);
			return array();
		}
		if ($len+4 > strlen($pkt)) return false;
		$res = explode(',', substr($pkt, 4, $len));
		$pkt = substr($pkt, $len+4);
		return $res;
	}

	protected function loadSkey() {
		switch($this->capa['key_alg']) { // ssh-dss,ssh-rsa
			case 'ssh-rsa': $key = PINETD_ROOT.'/ssl/ssh_host_rsa_key'; break;
			case 'ssh-dss': $key = PINETD_ROOT.'/ssl/ssh_host_dsa_key'; break;
			default: return false;
		}
		if (!file_exists($key)) return false;
		$pkey = file_get_contents($key);
		$pub = explode(' ', file_get_contents($key.'.pub'));
		$pub = base64_decode($pub[1]);
		$pkeyid = openssl_get_privatekey($pkey);
		$this->skey = array('priv' => $pkey, 'pub' => $pub, 'pkeyid' => $pkeyid, 'type' => $this->capa['key_alg']);
		return true;
	}

	protected function nameList(array $list) {
		$res = implode(',', $list);
		return pack('N', strlen($res)).$res;
	}

	protected function getCompAlgList() {
		return array('none'); // zlib@openssl.com
	}

	protected function getCipherAlgList() {
		$mapping = array(
			'rijndael-256' => 'aes256-cbc',
			'rijndael-192' => 'aes192-cbc',
			'rijndael-128' => 'aes128-cbc',
			'blowfish' => 'blowfish-cbc',
			'serpent' => 'serpent256-cbc',
//			'arcfour' => 'arcfour', // Only cbc for now
			'cast-128' => 'cast128-cbc',
			'tripledes' => '3des-cbc',
		);
		$list = mcrypt_list_algorithms();
		$final = array();
		foreach($list as $alg) if (isset($mapping[$alg])) $final[$mapping[$alg]] = $mapping[$alg];
		// reorder by priority
		$final_ord = array();
		foreach($mapping as $alg) if (isset($final[$alg])) $final_ord[] = $alg;
		return $final_ord;
	}

	protected function getHmacAlgList() {
		return array('hmac-sha1','hmac-sha1-96','hmac-md5','hmac-md5-96','none');
	}

	protected function getKeyExchAlgList() {
		return array('diffie-hellman-group1-sha1');//,'diffie-hellman-group14-sha1');
	}

	protected function getPKAlgList() {
		return array('ssh-dss','ssh-rsa');
	}

	protected function str($str) {
		return pack('N', strlen($str)).$str;
	}

	protected function sendPacket($packet) {
		$len = strlen($packet);
		// compute padding length
		$pad_len = max($this->cipher['send']['block_size'], 8) - (($len+5) % max($this->cipher['send']['block_size'], 8));
		if ($pad_len < 4) $pad_len += max($this->cipher['send']['block_size'], 8);
		$packet = pack('NC', $len+1+$pad_len, $pad_len).$packet.str_repeat("\0", $pad_len);
		// TODO: add MAC
		return $this->sendMsg($packet);
	}

	protected function handleProtocol($proto) {
		if (!preg_match('/^SSH-2\\.0-([^ -]+)( .*)?$/', $proto, $matches)) {
			$this->sendMsg("I hate you.\n");
			$this->close();
		}
		$this->payloads['V_C'] = $proto;
		$this->agent = $matches[1].$matches[2];
		$this->state = 'login';
		$this->ssh_sendAlgorithmNegotiationPacket();
	}

	protected function parseBuffer() {
		while($this->ok) {
			if($this->state == 'new') {
				$pos = strpos($this->buf, "\n");
				if ($pos === false) break;
				$pos++;
				$lin = substr($this->buf, 0, $pos);
				$this->buf = substr($this->buf, $pos);
				$this->handleProtocol(rtrim($lin));
				continue;
			}

			if (strlen($this->clearbuf) > 4) {
				list(,$len) = unpack('N', $this->clearbuf);
				if (strlen($this->clearbuf) >= (4+$len)) {
					$pkt = substr($this->clearbuf, 4, $len);
					$this->clearbuf = substr($this->clearbuf, $len+4);
					$padding = ord($pkt[0]);
					$pkt = substr($pkt, 1, 0-$padding);
					$this->handlePkt($pkt);
				}
			}

			if (strlen($this->buf) < $this->cipher['recv']['block_size']) break; // no enough data
//			$len = floor(strlen($this->buf) / $this->cipher['block_size']) * $this->cipher['block_size'];
			$len = $this->cipher['recv']['block_size'];
			$tmp = substr($this->buf, 0, $len);
			$this->buf = substr($this->buf, $len);
			// TODO: decrypt $tmp if needed
			$this->clearbuf .= $tmp;
		}
		$this->setProcessStatus(); // back to idle
	}

	function shutdown() {
	}
}

