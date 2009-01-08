<?php

namespace Daemon\SimpleBNC;

class Client extends \pinetd\Process {

	protected $connected =	array();

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
		$this->IPC->createPort('SimpleBNC::Transport', $this);
	}

	public function mainLoop() {
		while(1) {
			$this->IPC->selectSockets(200000);
		}
	}

	public function shutdown() {
	}

	public function isConnected($login) {
		if (!array_key_exists($login, $this->connected)) {
			return false;
		}
		return true;
	}

	public function write($raw) {
		if (!count($this->connections)) {
			return;
		}
		foreach($this->connections as $key => $fd) {
			$return = fwrite($fd, $raw);
			var_dump($return, strlen($raw));
		}
	}

	public function connect($socket, $user) {
		$fd	=	stream_socket_client($socket, $errCode, $errStr);
		if (!$fd) {
			$this->notifyClients($user, "Unable to connect to $tcp");
		} else {
			$this->notifyClients($user, "Connecting to $socket....");
		}
		$arrayFD = array($fd);
		$this->IPC->registerSocketWait($fd, array($this, 'parseLine'), $arrayFD);
		$this->connections[$fd] = $fd;
		fwrite($fd, "NICK SimpleBNC\n");
		fwrite($fd, "USER SimpleBNC SimpleBNC SimpleBNC : SimpleBNC\n");
		fwrite($fd, "JOIN #php\n");
	}

	public function parseLine($line) {
		$line = rtrim(fgets($line, 4096));
		$this->notifyClients('grepsd', $line);
		if ($line != "0\n") {
			file_put_contents('logSimpleBNC', $line, FILE_APPEND);
		}
	}

	public function notifyClients($user, $string) {
		if (!count($this->clients[$user])) {
			return false;
		}
		foreach($this->clients[$user] as &$client) {
			$client[3]->sendMsg($string);
		}
	}

	public function registerClient($login, $portName, $peer) {
		$this->clients[$login][]	=	array($peer[0], $peer[1], $portName, $this->IPC->openPort($portName));
	}
}
