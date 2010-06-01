<?php

// socket policy daemon for flash, supposed to run on port 843

namespace Daemon\SocketPolicyd;
use pinetd\Logger;

class Base extends \pinetd\TCP\Base {
	public function doAccept($sock) {
		// overload to avoid resolving & forking
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
		$this->IPC->setTimeOut($news, 15, array($new, 'socketTimedOut'), $foo = array());
	}

	public function _ChildIPC_getPolicyData() {
		return $this->getPolicyData();
	}

	public function getPolicyData() {
		$policy = '<?xml version="1.0"?>';
		$policy .= '<!DOCTYPE cross-domain-policy SYSTEM "/xml/dtds/cross-domain-policy.dtd">';
		$policy .= '<cross-domain-policy>';
		$policy .= '<site-control permitted-cross-domain-policies="master-only"/>';
		foreach($this->localConfig['PolicyArray']['Policy'] as $p) {
			$policy .= '<allow-access-from domain="'.htmlspecialchars($p['Domain']?:'*').'" to-ports="'.htmlspecialchars($p['Ports']?:'*').'" />';
		}
		$policy .= '</cross-domain-policy>';
		return $policy;
	}
}

