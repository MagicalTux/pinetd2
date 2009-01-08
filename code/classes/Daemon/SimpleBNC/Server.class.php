<?php

namespace Daemon\SimpleBNC;
use pinetd\Logger;
use pinetd\SQL;

class Server extends \pinetd\TCP\Base {
    
    public function __construct() {
        call_user_func(array('parent', '__construct'), func_get_args());

        $this->sql = SQL::Factory($this->localConfig['Storage']);
        $this->IPC->createPort('SimpleBNC::Parent', $this);
    }


    function spawnClient($socket, $peer, $parent, $protocol) {
        $class = relativeclass($this, 'Server_Thread');
        return new $class($socket, $peer, $parent, $protocol);
	}

    public function getSQLInstance() {
        return $this->sql;
    }
}
