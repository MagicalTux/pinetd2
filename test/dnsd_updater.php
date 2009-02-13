<?php

class DNSd_updater {
	private $fp;
	private $buf;

	function __construct($peername, $ip, $secret, $port = 53) {
		// connect to host
		$this->fp = fsockopen($ip, $port, $errno, $errstr, 5);
		if (!$this->fp) throw new Exception('['.$errno.'] '.$errstr);

		$pkt = $peername.pack('N', time());
		$pkt .= sha1($pkt.$secret, true); // signature
		$pkt = 'BEGIN' . $pkt;
		fputs($this->fp, pack('n', strlen($pkt)).$pkt);

		// read reply
		$pkt = $this->readPkt();
		$signature = sha1(substr($pkt, 0, -20).$secret, true);
		if ($signature != substr($pkt, -20)) {
			fclose($this->fp);
			throw new Exception('Bad signature from server!');
		}
		$pkt = substr($pkt, 0, -20);
		list(,$stamp) = unpack('N', substr($pkt, -4));
		if (abs(time()-$stamp) > 5) throw new Exception('Invalid time synchronization between client & server');

		$node = substr($pkt, 0, -4);
		var_dump($node);
	}

	protected function readPkt() {
		$first = true;
		while(1) {
			if ($first) {
				$first = false;
			} else {
				$this->buf .= fread($this->fp, 4096);
			}

			if (strlen($this->buf) < 2) continue;

			list(,$len) = unpack('n', $this->buf);
			if ($len + 2 > strlen($this->buf)) continue;

			$pkt = substr($this->buf, 2, $len);
			$this->buf = (string)substr($this->buf, $len+2);

			return $pkt;
		}
	}
}

$dnsd = new DNSd_updater('MyPeer', '127.0.0.1', 'azerty', 10053);

