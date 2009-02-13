<?php

namespace Daemon\DNSd;

use pinetd\Logger;

class TCP extends \pinetd\TCP\Base {
	protected function handlePacket($pkt, $peer) {
		$this->engine->handlePacket($pkt, $peer);
		//Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}

	public function sendReply($pkt, $peer) {
		$this->sendPacket($pkt, $peer);
	}

	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'TCP_Client');
		return new $class($socket, $peer, $parent, $protocol);
	}

	public function _ChildIPC_getUpdateSignature(&$daemon, $node) {
		return $this->getUpdateSignature($node);
	}

	public function getUpdateSignature($node) {
		// TODO: use different signatures per node
		return $this->localConfig['UpdateSignature']['_'];
	}

	public function getNodeName() {
		return $this->localConfig['Name']['_'];
	}

	public function doAccept($sock) {
		// Overload this to avoid useless things (like remote host resolving)
		$news = @stream_socket_accept($sock, 0, $peer);
		if (!$news) return;
		$new = $this->spawnClient($news, $peer, $this, $this->protocol);
		if (!$new) {
			@fclose($news);
			return;
		}
		if (!$new->welcomeUser()) {
			$new->close();
			return;
		}
		Logger::log(Logger::LOG_DEBUG, 'Accepting new client on port '.$this->daemon['Port'].' from '.$peer);
		$this->clients[$news] = array(
			'socket' => $news,
			'obj' => $new,
			'peer' => $peer,
			'connect' => time(),
		);
		$new->sendBanner();
		$this->IPC->registerSocketWait($news, array($new, 'readData'), $foo = array());
	}
}

