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
			$this->nodes[$t[0]] = array('ip' => $t[0], 'name' => $t[1]);
		}

		$this->heartbeat = $heartbeat;
		$this->password = $password;

		for($i = 0; $i < 50; $i++) {
			var_dump(bin2hex($this->makePkt()));
			sleep(1);
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

