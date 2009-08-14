<?php

namespace Daemon\DNSd;
use pinetd\Logger;
use pinetd\SQL;

class TCP_Peer extends \pinetd\TCP\Client {
	private $unique;

	public function welcomeUser($unique) {
		$this->setMsgEnd('');
		$this->unique = $unique;
		return true; // nothing to say
	}

	public function sendBanner() {
//		$this->port = $this->IPC->openPort('DNSd::DbEngine');
	}

	protected function receivePacket($pkt) {
		$data = unserialize($pkt);
		try {
			$res = $this->IPC->callPort('DNSd::DbEngine::'.$this->unique(), $data[0], $data[1]);
		} catch(Exception $e) {
			$res = $e;
		}
		$this->sendReply(serialize($res));
	}

	public function sendReply($pkt, $peer = NULL) {
		$this->sendMsg(pack('n', strlen($pkt)) . $pkt);
	}

	protected function parseBuffer() {
		while($this->ok) {
			if (strlen($this->buf) < 2) break;
			$len = unpack('n', $this->buf);
			$len = $len[1];
			if (strlen($this->buf) < (2+$len)) break;

			$dat = substr($this->buf, 2, $len);
			$this->buf = substr($this->buf, $len+2);
			$this->receivePacket($dat);
		}
	}
}

