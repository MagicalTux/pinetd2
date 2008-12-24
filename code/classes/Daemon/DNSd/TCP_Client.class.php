<?php

namespace Daemon\DNSd;

class TCP_Client extends \pinetd\TCP\Client {
	private $engine;

	public function welcomeUser() {
		$this->setMsgEnd('');
		return true; // nothing to say
	}

	public function sendBanner() {
		$class = relativeclass($this, 'Engine');
		$this->engine = new $class($this, $this->IPC);
	}

	protected function receivePacket($pkt) {
		$this->engine->handlePacket($pkt, NULL);
	}

	public function sendReply($pkt, $peer) {
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

