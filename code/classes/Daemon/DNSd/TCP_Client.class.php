<?php

namespace Daemon\DNSd;
use pinetd\Logger;

class TCP_Client extends \pinetd\TCP\Client {
	private $engine;
	private $syncmode = false;

	public function welcomeUser() {
		$this->setMsgEnd('');
		return true; // nothing to say
	}

	public function sendBanner() {
		$class = relativeclass($this, 'Engine');
		$this->engine = new $class($this, $this->IPC);
	}

	protected function receivePacket($pkt) {
		if (substr($pkt, 0, 5) == 'BEGIN') {
			$pkt = substr($pkt, 5);
			// read packet
			list(,$stamp) = @unpack('N', substr($pkt, -24, 4));
			$node = substr($pkt, 0, -24);
			// check signature
			$signature = sha1(substr($pkt, 0, -20).$this->IPC->getUpdateSignature($node), true);
			if ($signature != substr($pkt, -20)) {
				// bad signature
				Logger::log(Logger::LOG_WARN, 'Bad signature from client at '.$this->peer[0]);
				$resp = 'BAD';
				$this->sendMsg(pack('n', strlen($resp)).$resp);
				$this->close();
				return;
			}
			// check stamp
			if (abs(time() - $stamp) > 5) {
				// bad timestamp
				$resp = 'BAD';
				$this->sendMsg(pack('n', strlen($resp)).$resp);
				$this->close();
				return;
			}

			// auth OK, enable advanced protocol
			$this->syncmode = true;

			$resp = $this->IPC->getNodeName().pack('N', time());
			// add signature
			$resp .= sha1($resp.$this->IPC->getUpdateSignature($node), true);

			$this->sendMsg(pack('n', strlen($resp)).$resp);
			return;
		}

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

