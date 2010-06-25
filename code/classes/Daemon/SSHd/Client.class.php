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
	private $session_id = NULL;
	private $seq_recv = -1;
	private $seq_send = -1;
	private $login = NULL;

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

	const SSH_MSG_USERAUTH_REQUEST = 50;
	const SSH_MSG_USERAUTH_FAILURE = 51;
	const SSH_MSG_USERAUTH_SUCCESS = 52;
	const SSH_MSG_USERAUTH_BANNER = 53;

	const SSH_MSG_USERAUTH_PASSWD_CHANGEREQ = 60;
	const SSH_MSG_USERAUTH_PK_OK = 60;

	const SSH_MSG_GLOBAL_REQUEST = 80;
	const SSH_MSG_REQUEST_SUCCESS = 81;
	const SSH_MSG_REQUEST_FAILURE = 82;

	const SSH_MSG_CHANNEL_OPEN = 90;
	const SSH_MSG_CHANNEL_OPEN_CONFIRMATION = 91;
	const SSH_MSG_CHANNEL_OPEN_FAILURE = 92;
	const SSH_MSG_CHANNEL_WINDOW_ADJUST = 93;
	const SSH_MSG_CHANNEL_DATA = 94;
	const SSH_MSG_CHANNEL_EXTENDED_DATA = 95;
	const SSH_MSG_CHANNEL_EOF = 96;
	const SSH_MSG_CHANNEL_CLOSE = 97;
	const SSH_MSG_CHANNEL_REQUEST = 98;
	const SSH_MSG_CHANNEL_SUCCESS = 99;
	const SSH_MSG_CHANNEL_FAILURE = 100;

	const SSH_DISCONNECT_HOST_NOT_ALLOWED_TO_CONNECT = 1;
	const SSH_DISCONNECT_PROTOCOL_ERROR = 2;
	const SSH_DISCONNECT_KEY_EXCHANGE_FAILED = 3;
	const SSH_DISCONNECT_RESERVED = 4;
	const SSH_DISCONNECT_MAC_ERROR = 5;
	const SSH_DISCONNECT_COMPRESSION_ERROR = 6;
	const SSH_DISCONNECT_SERVICE_NOT_AVAILABLE = 7;
	const SSH_DISCONNECT_PROTOCOL_VERSION_NOT_SUPPORTED = 8;
	const SSH_DISCONNECT_HOST_KEY_NOT_VERIFIABLE = 9;
	const SSH_DISCONNECT_CONNECTION_LOST = 10;
	const SSH_DISCONNECT_BY_APPLICATION = 11;
	const SSH_DISCONNECT_TOO_MANY_CONNECTIONS = 12;
	const SSH_DISCONNECT_AUTH_CANCELLED_BY_USER = 13;
	const SSH_DISCONNECT_NO_MORE_AUTH_METHODS_AVAILABLE = 14;
	const SSH_DISCONNECT_ILLEGAL_USER_NAME = 15;

	function welcomeUser() {
		$this->state = 'new';
		$this->cipher['send'] = array('name' => 'NULL', 'block_size' => 1, 'hmac' => 'none');
		$this->cipher['recv'] = array('name' => 'NULL', 'block_size' => 1, 'hmac' => 'none');
		$this->setMsgEnd('');
		$this->sendMsg("Please wait while resolving your hostname...\r\n");
		return true;
	}

	public function sendBanner() {
		$this->sendMsg("SSH-2.0-pinetd PHP/".phpversion()."\r\n");
		$this->payloads['V_S'] = "SSH-2.0-pinetd PHP/".phpversion();
	}

	protected function handlePkt($pkt) {
		$id = ord($pkt[0]);
		if (($id > 69) && (is_null($this->login))) {
			$this->disconnect(self::SSH_DISCONNECT_PROTOCOL_ERROR, 'need to login first');
			return;
		}

		switch(ord($pkt[0])) {
			case self::SSH_MSG_DISCONNECT:
				$this->close();
				break;
			case self::SSH_MSG_IGNORE:
			case self::SSH_MSG_UNIMPLEMENTED:
			case self::SSH_MSG_DEBUG:
				// fake/useless traffic to keep cnx alive and confuse listeners
				return;
			case self::SSH_MSG_SERVICE_REQUEST:
				list(,$len) = unpack('N', substr($pkt, 1, 4));
				$text = substr($pkt, 5, $len);
				$res = $this->ssh_serviceInit($text);
				if ($res) {
					$pkt = chr(self::SSH_MSG_SERVICE_ACCEPT).$this->str($text);
					$this->sendPacket($pkt);
				} else {
					$this->disconnect(self::SSH_DISCONNECT_SERVICE_NOT_AVAILABLE, 'requested service '.$text.' not available');
				}
				break;
			case self::SSH_MSG_KEXINIT:
				$this->payloads['I_C'] = $pkt;
				$data = array();
				$data['cookie'] = bin2hex(substr($pkt, 1, 16));
				$pkt = substr($pkt, 17); // remove packet code (1 byte) & cookie (16 bytes)
				$list_list = array('kex_algorithms','server_host_key_algorithms','encryption_algorithms_client_to_server','encryption_algorithms_server_to_client','mac_algorithms_client_to_server','mac_algorithms_server_to_client','compression_algorithms_client_to_server','compression_algorithms_server_to_client','languages_client_to_server','languages_server_to_client');
				foreach($list_list as $list) {
					$res = $this->parseNameList($pkt);
					if ($res === false) {
						$this->disconnect(self::SSH_DISCONNECT_PROTOCOL_ERROR, 'Failed to parse KEX packet');
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
				if (!isset($this->capa['K'])) {
					Logger::log(Logger::LOG_WARN, "Client trying to enable encryption without key exchange");
					$this->disconnect(self::SSH_DISCONNECT_PROTOCOL_ERROR, 'you cannot enable encryption without kex first');
					break;
				}
				$this->sendPacket(chr(self::SSH_MSG_NEWKEYS)); // send before we enable send encryption
				$this->ssh_initEncryption();
				break;
			case self::SSH_MSG_KEXDH_INIT: $this->ssh_KeyExchangeDHInit($pkt); break;

				/***************************** USER AUTH ****************************/

			case self::SSH_MSG_USERAUTH_REQUEST:
				$pkt = substr($pkt, 1);
				$user = $this->parseStr($pkt);
				$service = $this->parseStr($pkt);
				$method = $this->parseStr($pkt);
				$this->ssh_handleUserAuthRequest($user, $service, $method, $pkt);
				break;

				/**************************** CHANNELS *****************************/

			case self::SSH_MSG_CHANNEL_OPEN:
			default:
				echo "Unknown packet: ".bin2hex($pkt)."\n";
				$pkt = pack('CN', self::SSH_MSG_UNIMPLEMENTED, $this->seq_recv);
				$this->sendPacket($pkt);
		}
	}

	protected function login($login, $password, $service) {
		$info = $this->IPC->checkAccess($login, $password, $this->peer, $service);
		if (!$info) return false;
		$this->login = $info['login'];
		return true;
	}

	protected function ssh_handleUserAuthRequest($user, $service, $method, $pkt) {
		if ($service != 'ssh-connection') {
			$pkt = chr(self::SSH_MSG_USERAUTH_FAILURE).$this->str('').chr(0);
			$this->sendPacket($pkt);
			return;
		}
		switch($method) {
			case 'password':
				$change = (bool)ord($pkt[0]);
				$pkt = substr($pkt, 1);
				$password = $this->parseStr($pkt);
				if (!$this->login($user, $password, $service)) break;
				$pkt = chr(self::SSH_MSG_USERAUTH_SUCCESS);
				$this->sendPacket($pkt);
				return;
			case 'publickey':
				break; // ?
		}
		$pkt = chr(self::SSH_MSG_USERAUTH_FAILURE).$this->str('publickey,password').chr(0);
		$this->sendPacket($pkt);
	}

	protected function ssh_serviceInit($svc) {
		if ($svc == 'ssh-userauth') return true; // builtin
		var_dump($svc);
		return false;
	}

	protected function ssh_initEncryption() {
		// init I/O encryption
		$mapping = array(
			'aes256-cbc' => array(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC, 32),
			'aes192-cbc' => array(MCRYPT_RIJNDAEL_192, MCRYPT_MODE_CBC, 24),
			'aes128-cbc' => array(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC, 16),
			'blowfish-cbc' => array(MCRYPT_BLOWFISH, MCRYPT_MODE_CBC, 32),
			'serpent256-cbc' => array(MCRYPT_SERPENT, MCRYPT_MODE_CBC, 16),
//			'arcfour', // Only cbc for now
			'cast128-cbc' => array(MCRYPT_CAST_128, MCRYPT_MODE_CBC, 16),
			'3des-cbc' => array(MCRYPT_3DES, MCRYPT_MODE_CBC, 16),
		);

		$alg_recv = $mapping[$this->capa['cipher_recv']];
		$alg_send = $mapping[$this->capa['cipher_send']];
		$hmac_recv = $this->capa['hmac_recv'];
		$hmac_send = $this->capa['hmac_send'];
		// TODO: handle compression activation too

		$iv_recv = sha1($this->capa['K'].$this->capa['H'].'A'.$this->session_id, true);
		$iv_send = sha1($this->capa['K'].$this->capa['H'].'B'.$this->session_id, true);
		$key_recv = sha1($this->capa['K'].$this->capa['H'].'C'.$this->session_id, true);
		$key_send = sha1($this->capa['K'].$this->capa['H'].'D'.$this->session_id, true);
		$mac_recv = sha1($this->capa['K'].$this->capa['H'].'E'.$this->session_id, true);
		$mac_send = sha1($this->capa['K'].$this->capa['H'].'F'.$this->session_id, true);

		$key_recv_len = $alg_recv[2];
		$key_send_len = $alg_send[2];
		$iv_recv_len = mcrypt_get_iv_size($alg_recv[0], $alg_recv[1]);
		$iv_send_len = mcrypt_get_iv_size($alg_send[0], $alg_recv[1]);
		$block_recv = mcrypt_module_get_algo_block_size($alg_recv[0]);
		$block_send = mcrypt_module_get_algo_block_size($alg_send[0]);

		$mac_key_len_recv = strlen($mac_recv);
		$mac_key_len_send = strlen($mac_send);
		switch($hmac_recv) {
			case 'hmac-sha1': case 'hmac-sha1-96': $mac_key_len_recv = 20; break;
			case 'hmac-md5': case 'hmac-md5-96': $mac_key_len_recv = 16; break;
		}
		switch($hmac_send) {
			case 'hmac-sha1': case 'hmac-sha1-96': $mac_key_len_send = 20; break;
			case 'hmac-md5': case 'hmac-md5-96': $mac_key_len_send = 16; break;
		}

		if ($key_recv_len < $block_recv) $key_recv_len = $block_recv;
		if ($key_send_len < $block_send) $key_send_len = $block_send;

		while(strlen($key_recv) < $key_recv_len) $key_recv .= sha1($this->capa['K'].$this->capa['H'].$key_recv, true);
		while(strlen($key_send) < $key_send_len) $key_send .= sha1($this->capa['K'].$this->capa['H'].$key_send, true);
		if (strlen($key_recv) > $key_recv_len) $key_recv = substr($key_recv, 0, $key_recv_len);
		if (strlen($key_send) > $key_send_len) $key_send = substr($key_send, 0, $key_send_len);

		while(strlen($iv_recv) < $iv_recv_len) $iv_recv .= sha1($this->capa['K'].$this->capa['H'].$iv_recv, true);
		while(strlen($iv_send) < $iv_send_len) $iv_send .= sha1($this->capa['K'].$this->capa['H'].$iv_send, true);
		if (strlen($iv_recv) > $iv_recv_len) $iv_recv = substr($iv_recv, 0, $iv_recv_len);
		if (strlen($iv_send) > $iv_send_len) $iv_send = substr($iv_send, 0, $iv_send_len);

		if (strlen($mac_recv) > $mac_key_len_recv) $mac_recv = substr($mac_recv, 0, $mac_key_len_recv);
		if (strlen($mac_send) > $mac_key_len_send) $mac_send = substr($mac_send, 0, $mac_key_len_send);

		$mod_recv = mcrypt_module_open($alg_recv[0], '', $alg_recv[1], '');
		$mod_send = mcrypt_module_open($alg_send[0], '', $alg_send[1], '');
		mcrypt_generic_init($mod_recv, $key_recv, $iv_recv);
		mcrypt_generic_init($mod_send, $key_send, $iv_send);

		$this->cipher['recv'] = array('name' => $this->capa['cipher_recv'], 'block_size' => $block_recv, 'mod' => $mod_recv, 'hmac' => $hmac_recv, 'hmac_key' => $mac_recv);
		$this->cipher['send'] = array('name' => $this->capa['cipher_send'], 'block_size' => $block_send, 'mod' => $mod_send, 'hmac' => $hmac_send, 'hmac_key' => $mac_send);
	}

	protected function ssh_KeyExchangeDHInit($pkt) {
		// secure key exchange - diffie-hellman-group1-sha1
		if (!$this->loadSkey()) {
			Logger::log(Logger::LOG_WARN, 'Could not load server key');
			$this->disconnect(self::SSH_DISCONNECT_KEY_EXCHANGE_FAILED, 'server misconfiguration');
			return;
		}
		// RFC 4253 page 21: 8. Diffie-Hellman Key Exchange
		$p = gmp_init('179769313486231590770839156793787453197860296048756011706444423684197180216158519368947833795864925541502180565485980503646440548199239100050792877003355816639229553136239076508735759914822574862575007425302077447712589550957937778424442426617334727629299387668709205606050270810842907692932019128194467627007');

		// read $e from client
		list(,$len) = unpack('N', substr($pkt, 1, 4));
		$e_bin = substr($pkt, 5, $len);
		$e = gmp_init(bin2hex($e_bin), 16); // not really optimized but works

		$y = gmp_init(bin2hex(openssl_random_pseudo_bytes(64)), 16);

		// compute $f and $K
		$f = gmp_powm(2, $y, $p);
		$f_bin = pack('H*', gmp_strval($f, 16));
		if (ord($f_bin[0]) & 0x80) $f_bin = "\0" . $f_bin;
		// TODO: it seems that sometimes we fail to compute a valid $K. This is totally random
		$K = gmp_powm($e, $y, $p);
		$K_bin = pack('H*', gmp_strval($K, 16));
		if (ord($K_bin[0]) & 0x80) $K_bin = "\0" . $K_bin;

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
		$H = sha1(implode('', $sha), true);

		// store shared secret in session
		$this->capa['K'] = $this->str($K_bin);
		$this->capa['H'] = $H;
		if (is_null($this->session_id)) $this->session_id = $H;

		// sign $H
		if (!openssl_sign($H, $s, $this->skey['pkeyid'])) {
			Logger::log(Logger::LOG_WARN, 'Could not sign exchange key');
			$this->disconnect(self::SSH_DISCONNECT_KEY_EXCHANGE_FAILED, 'Failed to sign exchange hash');
			return;
		}

		$s = $this->str($this->skey['type']).$this->str($s);

		$pkt = chr(self::SSH_MSG_KEXDH_REPLY);
		$pkt .= $this->str($pub);
		$pkt .= $this->str($f_bin);
		$pkt .= $this->str($s);
		$this->sendPacket($pkt);
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

	protected function parseStr(&$pkt) {
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
			'rijndael-128' => 'aes128-cbc',
			'rijndael-192' => 'aes192-cbc',
			'rijndael-256' => 'aes256-cbc',
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

	protected function disconnect($code, $text) {
		$pkt = chr(self::SSH_MSG_DISCONNECT);
		$pkt .= pack('N', $code);
		$pkt .= $this->str($text);
		$pkt .= $this->str('C');
		$this->sendPacket($pkt);
		$this->close();
	}

	protected function str($str) {
		return pack('N', strlen($str)).$str;
	}

	protected function sendPacket($packet) {
		$this->seq_send++;
		$len = strlen($packet);
		// compute padding length
		$pad_len = max($this->cipher['send']['block_size'], 8) - (($len+5) % max($this->cipher['send']['block_size'], 8));
		if ($pad_len < 4) $pad_len += max($this->cipher['send']['block_size'], 8);
		$packet = pack('NC', $len+1+$pad_len, $pad_len).$packet.str_repeat("\0", $pad_len);
		
		// compute hmac
		$mac = '';
		switch($this->cipher['send']['hmac']) {
			case 'hmac-sha1': $mac = hash_hmac('sha1', pack('N', $this->seq_send).$packet, $this->cipher['send']['hmac_key'], true); break;
			case 'hmac-sha1-96': $mac = substr(hash_hmac('sha1', pack('N', $this->seq_send).$packet, $this->cipher['send']['hmac_key'], true), 0, 12); break;
			case 'hmac-md5': $mac = hash_hmac('md5', pack('N', $this->seq_send).$packet, $this->cipher['send']['hmac_key'], true); break;
			case 'hmac-md5-96': $mac = substr(hash_hmac('md5', pack('N', $this->seq_send).$packet, $this->cipher['send']['hmac_key'], true), 0, 12); break;
		}
		// encrypt packet
		if (isset($this->cipher['send']['mod'])) {
			$packet = mcrypt_generic($this->cipher['send']['mod'], $packet);
		}
		return $this->sendMsg($packet.$mac);
	}

	protected function handleProtocol($proto) {
		if (!preg_match('/^SSH-2\\.0-([^ -]+)( .*)?$/', $proto, $matches)) {
			$this->disconnect(self::SSH_DISCONNECT_PROTOCOL_VERSION_NOT_SUPPORTED, 'could not understand your protocol version');
			return;
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

			$block_size = max(8, $this->cipher['recv']['block_size']);

			if (strlen($this->clearbuf) < 8) {
				if (strlen($this->buf) < $block_size) break; // not enough data yet
				// decrypt first block
				$tmp = substr($this->buf, 0, $block_size);
				$this->buf = substr($this->buf, $block_size);
				if (isset($this->cipher['recv']['mod']))
					$tmp = mdecrypt_generic($this->cipher['recv']['mod'], $tmp);
				$this->clearbuf .= $tmp;
			}

			// decode length from clearbuf
			list(,$len) = unpack('N', substr($this->clearbuf, 0, 4));

			if ($len > 256*1024) {
				$this->disconnect(self::SSH_DISCONNECT_PROTOCOL_ERROR, 'disconnected: packet size over 256kB');
			}

			if ((4+$len) > strlen($this->clearbuf)) { // missing data
				$missing_len = (4+$len)-strlen($this->clearbuf);
				if (strlen($this->buf) < $missing_len) break; // need more data
				$tmp = substr($this->buf, 0, $missing_len);
				$this->buf = substr($this->buf, $missing_len);
				if (isset($this->cipher['recv']['mod']))
					$tmp = mdecrypt_generic($this->cipher['recv']['mod'], $tmp);
				$this->clearbuf .= $tmp;
			}

			// we got one full packet, check for mac...
			$mac_len = 0;
			switch($this->cipher['recv']['hmac']) {
				case 'hmac-sha1': $mac_len = 20; break;
				case 'hmac-md5': $mac_len = 16; break;
				case 'hmac-sha1-96': 
				case 'hmac-md5-96': $mac_len = 12; break;
			}
			if (strlen($this->buf) < $mac_len) break; // need more data

			$this->seq_recv++; // we got all the data we needed, we can increment this counter

			if ($mac_len > 0) {
				switch($this->cipher['recv']['hmac']) {
					case 'hmac-sha1': $mac = hash_hmac('sha1', pack('N', $this->seq_recv).$this->clearbuf, $this->cipher['recv']['hmac_key'], true); break;
					case 'hmac-sha1-96': $mac = substr(hash_hmac('sha1', pack('N', $this->seq_recv).$this->clearbuf, $this->cipher['recv']['hmac_key'], true), 0, 12); break;
					case 'hmac-md5': $mac = hash_hmac('md5', pack('N', $this->seq_recv).$this->clearbuf, $this->cipher['recv']['hmac_key'], true); break;
					case 'hmac-md5-96': $mac = substr(hash_hmac('md5', pack('N', $this->seq_recv).$this->clearbuf, $this->cipher['recv']['hmac_key'], true), 0, 12); break;
				}
				$tmp = substr($this->buf, 0, $mac_len);
				$this->buf = substr($this->buf, $mac_len);
				if ($tmp != $mac) {
					// TODO: send a disconnect packet
					Logger::log(Logger::LOG_WARN, 'Invalid MAC for packet in input stream!');
					$this->disconnect(self::SSH_DISCONNECT_MAC_ERROR, 'could not understand mac');
					break;
				}
			}

			$pkt = substr($this->clearbuf, 4);
			$this->clearbuf = '';

			$padding = ord($pkt[0]);
			$pkt = substr($pkt, 1, 0-$padding);
			$this->handlePkt($pkt);
		}
		$this->setProcessStatus(); // back to idle
	}

	function shutdown() {
	}
}

