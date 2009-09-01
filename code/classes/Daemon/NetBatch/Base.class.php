<?php

namespace Daemon\NetBatch;

class Base extends \pinetd\TCP\Base {
	public function _ChildIPC_getRemotePeer(&$daemon, $login) {
		return $this->getRemotePeer($login);
	}

	public function getRemotePeer($login) {
		$remote = $this->localConfig['RemoteArray']['Remote'];

		foreach($remote as $peer) {
			if ($peer['login'] == $login)
				return $peer;
		}

		return NULL;
	}
}

