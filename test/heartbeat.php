<?php

class Heartbeat {
	private $nodes = array();
	private $heartbeat;
	private $password;

	const PKT_REPLY_ADDED = 1; /* dnsd loaded us from db */
	const PKT_REPLY_SYNC = 2; /* we are out of sync (date/time not valid) */
	const PKT_REPLY_BADPASS = 3; /* bad password */
	const PKT_REPLY_DUPE = 4; /* server already got us from somewhere else */

	public function __construct($domain, $heartbeat, $password) {
		// get peers for this heartbeat domain
		if (PHP_INT_SIZE < 8) throw new Exception('This program requires 64 bits integers');
		$list = dns_get_record('_heartbeat._dns.'.$domain, DNS_TXT);
		foreach($list as $record) {
			if ($record['class'] != 'IN') continue;
			if ($record['type'] != 'TXT') continue;
			$t = explode(' ', $record['txt']);
			$fd = fsockopen('udp://'.$t[0], 53);
			$this->nodes[(int)$fd] = array('fd' => $fd, 'ip' => $t[0], 'name' => $t[1]);
		}

		$this->heartbeat = $heartbeat;
		$this->password = $password;
	}

	public function loop() {
		$next_announce = microtime(true) + 2;
		while(1) {
			$list = array();
			foreach($this->nodes as $x)
				$list[] = $x['fd'];

			$n = stream_select($list, $w = null, $e = null, ceil($next_announce - microtime(true)));

			if ($n) {
				foreach($list as $fd) {
					$this->handleIn($fd);
				}
			}

			if ($next_announce < microtime(true)) {
				$this->sendAnnounce();
				$next_announce = microtime(true) + 10;
			}
		}
	}

	protected function sendAnnounce() {
		echo "Sending announce...\n";
		$pkt = $this->makePkt();
		foreach($this->nodes as $x) {
			stream_socket_sendto($x['fd'], $pkt);
		}
	}

	protected function handleIn($fd) {
		$x = &$this->nodes[(int)$fd];
		$pkt = stream_socket_recvfrom($fd, 128, 0, $addr);
		if (substr($pkt, 0, 8) != "HTBT\xff\xff\xff\xff") return; // ignore that
		$pkt = substr($pkt, 8);
		list(,$code) = unpack('N', $pkt);
		switch($code) {
			case self::PKT_REPLY_ADDED:
				$x['ack'] = true;
				break;
			case self::PKT_REPLY_SYNC:
				die("Server ".$x['name']." signals we are out of sync, please run ntpd!\n");
			case self::PKT_REPLY_BADPASS:
				die("Server ".$x['name']." signals the password is invalid\n");
			case self::PKT_REPLY_DUPE:
				die("Server ".$x['name']." signals there is already another active process!\n");
		}
	}

	protected function makePkt() {
		$loadavg = explode(' ', file_get_contents('/proc/loadavg'));
		$pkt = pack('NnnnN', $this->heartbeat, $loadavg[0]*100, $loadavg[1]*100, $loadavg[2]*100, getmypid());
		$pkt .= $this->getStamp();
		$pkt .= sha1($pkt.$this->password, true);
		return "HTBT\xff\xff\xff\xff".$pkt;
	}

	protected function getStamp() {
		$stamp = microtime(true);
		$stamp = (int)floor($stamp * 1000000);
		return pack('NN', ($stamp >> 32) & 0xffffffff, $stamp & 0xffffffff);
	}
}

$heartbeat = new Heartbeat('xta.net', 1, 'The password');
$heartbeat->loop();

