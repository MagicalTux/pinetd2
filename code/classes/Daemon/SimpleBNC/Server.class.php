<?php

namespace Daemon\SimpleBNC;
use pinetd\Logger;
use pinetd\SQL;

class Server extends \pinetd\TCP\Base {
	function spawnClient($socket, $peer, $parent, $protocol) {
        $this->sql = SQL::Factory($this->localConfig['Storage']);
        $this->IPC->createPort('SimpleBNC::Parent', $this);

        $class = relativeclass($this, 'Server_Thread');
        return new $class($socket, $peer, $parent, $protocol);
	}

    public function getSQLInstance() {
        return $this->sql;
    }
}
