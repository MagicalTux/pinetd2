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
		$this->engine = new $class($this, $this->IPC, $this->IPC->getLocalConfig());
	}

	protected function receivePacket($pkt) {
		if (substr($pkt, 0, 5) == 'BEGIN') {
			$pkt = substr($pkt, 5);
			// read packet
			list(,$stamp) = @unpack('N', substr($pkt, -24, 4));
			$node = substr($pkt, 0, -24);

			// check signature
			$peer = $this->IPC->getUpdateSignature($node);

			if (is_null($peer)) {
				// unknown peer
				Logger::log(Logger::LOG_WARN, 'Unknown DNS peer name (remember, those are case-sensitive) from client at '.$this->peer[0]);
				$resp = 'BAD';
				$this->sendMsg(pack('n', strlen($resp)).$resp);
				$this->close();
				return;
			}

			$signature = sha1(substr($pkt, 0, -20).$peer['Signature'], true);
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
			
			switch($peer['Type']) {
				case 'control':
					$class = 'TCP_Peer';
					break;
				case 'slave':
					$class = 'TCP_Slave';
					break;
				default:
					Logger::log(Logger::LOG_WARN, 'Bad type for identified client at '.$this->peer[0]);
					$resp = 'BAD';
					$this->sendMsg(pack('n', strlen($resp)).$resp);
					$this->close();
					return;
			}

			// auth OK, enable advanced protocol
			$this->syncmode = true;

			$resp = $this->IPC->getNodeName().pack('N', time());
			// add signature
			$resp .= sha1($resp.$peer['Signature'], true);

			$this->sendMsg(pack('n', strlen($resp)).$resp);

			if (!$this->IPC->forkIfYouCan($this->fd, $this->peer, 'TCP_Peer', $peer['Type'])) {
				// couldn't fork...
				Logger::log(Logger::LOG_WARN, 'Could not fork client at '.$this->peer[0]);
				$resp = 'BAD';
				$this->sendMsg(pack('n', strlen($resp)).$resp);
				$this->close();
				return;
			}

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

