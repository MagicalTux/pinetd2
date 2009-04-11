<?php

namespace Daemon\DNSd;

use pinetd\Logger;
use pinetd\SQL;
use pinetd\Timer;
use pinetd\IPC;

class TCP_IPC_Port {
	private $parent;
	public function __construct($parent) {
		$this->parent = $parent;
	}

	public function dispatch($table, $id, $data) {
		$this->parent->dispatch($table, $id, $data);
	}
}

class TCP extends \pinetd\TCP\Base {
	protected function handlePacket($pkt, $peer) {
		$this->engine->handlePacket($pkt, $peer);
		//Logger::log(Logger::LOG_DEBUG, 'Got an UDP packet from '.$peer);
	}

	public function mainLoop() {
		$port = new TCP_IPC_Port($this);
		$this->IPC->createPort('DNSd::TCPMaster', $port);
		return parent::mainLoop();
	}

	public function dispatch($table, $id, $data) {
		// dispatch to all slaves
		foreach($this->fclients as &$client) {
			if ($client['class'] == 'TCP_Slave') $client['IPC']->dispatch($table, $id, $data);
		}
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
		if (!isset($this->localConfig['PeersArray'])) return NULL;

		foreach($this->localConfig['PeersArray']['Peer'] as $peernode) {
			if ($peernode['Name'] == $node) {
				return $peernode;
			}
		}
		return NULL;
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
		$this->IPC->setTimeOut($news, 15, array($new, 'socketTimedOut'), $foo = array());
	}

	public function _ChildIPC_forkIfYouCan(&$daemon, $fd, $class) {
		return false; // already forked
	}
	function spawnClientClass($socket, $peer, $parent, $protocol, $class) {
		$class = relativeclass($this, $class);
		return new $class($socket, $peer, $parent, $protocol);
	}

	public function getLocalConfig() {
		return $this->localConfig;
	}

	public function callPort($port_name, $method, array $args, $wait = true) {
		return $this->IPC->callPort($port_name, $method, $args, $wait);
	}

	public function forkIfYouCan($news, $peer, $class) {
		if (!$news) return;

		$new = $this->spawnClientClass($news, $peer, $this, $this->protocol, $class);
		if (!$new) {
			@fclose($news);
			return;
		}
		if (!$new->welcomeUser()) {
			$new->close();
			return;
		}
		Logger::log(Logger::LOG_DEBUG, 'Forking new client on port '.$this->daemon['Port'].' from '.$peer[0]);
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		$pid = pcntl_fork();
		if ($pid > 0) {
			// we are parent
			$this->IPC->removeSocket($news);
			unset($this->clients[(int)$news]);

			fclose($news);
			fclose($pair[1]);
			unset($new);
			SQL::parentForked();
			$this->fclients[$pid] = array(
				'pid' => $pid,
				'peer' => $peer,
				'socket' => $pair[0],
				'IPC' => new \pinetd\IPC($pair[0], false, $this, $this->IPC),
				'connect' => time(),
				'class' => $class,
			);
			$this->IPC->registerSocketWait($pair[0], array($this->fclients[$pid]['IPC'], 'run'), $foobar = array(&$this->fclients[$pid]));
			return true;
		}
		if ($pid == 0) {
			// we are child
			SQL::forked();
			Timer::reset();
			unset($this->clients[(int)$news]);
			foreach($this->clients as $c) fclose($c['socket']);
			fclose($this->socket);
			fclose($pair[0]);
			$this->clients = array();

			$IPC = new IPC($pair[1], true, $foo = null, $bar = null);
			$IPC->ping(); // wait for parent to be ready
			Logger::setIPC($IPC);
			Logger::log(Logger::LOG_DEBUG, 'Daemon started for client, pid '.getmypid());
			$new->mainLoop($IPC);
			$IPC->Error('Exited from main loop!', 0);
			exit;
		}
		fclose($pair[0]);
		fclose($pair[1]);

		return false;
	}
}

