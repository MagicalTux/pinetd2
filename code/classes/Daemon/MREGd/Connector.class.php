<?php

namespace Daemon\MREGd;

use pinetd\Logger;
use pinetd\IPC;
use pinetd\Timer;

class Connector extends \pinetd\Process {
	private $mreg = array();
	private $queue;

	public function __construct($id, $daemon, $IPC, $node) {
		$this->queue = new \SplPriorityQueue();
		parent::__construct($id, $daemon, $IPC, $node);
	}

	public function IPCDied($fd) {
		$info = $this->IPC->getSocketInfo($fd);
		$this->IPC->removeSocket($fd);
		unset($this->runners[$info['pid']]);
//		$info = &$this->fclients[$info['pid']];
//		Logger::log(Logger::LOG_WARN, 'IPC for '.$info['pid'].' died');
	}

	public function _asyncPort_mreg(array $params, array $reply) {
		// need to add reply at end of reply, and call $this->IPC->routePortReply($reply, <is_exception>)
		$cmd = $params[0];
		$this->queue->insert(array('command' => $cmd, 'reply' => $reply), $params[1] ?: 0); // priority is second argument, but is optionnal
		$this->runQueue();
	}

	protected function runQueue() {
		if (!$this->queue->count()) return;

		foreach($this->mreg as &$cnx) {
			if ($cnx['status'] != 2) continue; // not ready
			if ($cnx['job']) continue; // processing a job

			// dequeue a job and pass it
			$cnx['job'] = $this->queue->current();
			$cnx['optime'] = time();
			$this->sendJob($cnx);
			$this->queue->next();
			if (!$this->queue->valid()) return; // no more stuff in queue
		}

		if (count($this->mreg) < 2) {
			$new = 2-count($this->mreg);
			for($i = 0; $i < $new; $i++)
				$this->startMreg();
		}

		if ($this->queue->count() > 0) {
			if (count($this->mreg) >= 5) continue; // can't run more mregs
			$this->startMreg();
		}
	}

	public function sendJob(&$cnx) {
		$cmd = $cnx['job']['command'];

		if (isset($cmd['command'])) {
			$cmdstr = "[METAREGISTRY]\r\nversion=1\r\n[COMMAND]\r\n";
			foreach($cmd as $var => $val) {
				$cmdstr .= $var.'='.$val."\r\n";
			}
		} else if (isset($cmd['operation'])) {
			$cmdstr = $cmd['operation']."\r\n";
			foreach($cmd as $var => $val) {
				if ($var == 'operation') continue;
				$cmdstr .= $var.':'.$val."\r\n";
			}
		}

		fwrite($cnx['fd'], $cmdstr.".\r\n");
		$cnx['idle'] = time();
	}

	public function startMreg() {
		// start a new mreg
		Logger::log(Logger::LOG_INFO, 'Connecting to MREG server...');
		$fd = stream_socket_client('tls://'.$this->localConfig['MREG']['Host'].':'.$this->localConfig['MREG']['Port'], $errno, $errstr, 10);
		if (!$fd) {
			Logger::log(Logger::LOG_WARN, 'Could not connect!! :(');
			return false;
		}
		stream_set_blocking($fd, false);
		$this->mreg[(int)$fd] = array(
			'fd' => $fd,
			'buf' => '',
			'idle' => time(),
			'optime' => time(),
			'status' => 0, // 0=waiting for initial input
		);
		$this->IPC->registerSocketWait($fd, array($this, 'mregData'), $e=array((int)$fd));
	}

	public function shutdown() {
		// send stop signal to clients
		Logger::log(Logger::LOG_INFO, 'MREG connector stopping...');
		foreach($this->mreg as &$cnx) {
			$cnx['job'] = array('command' => array('operation' => 'quit'));
			$cnx['status'] = -1;
			$this->sendJob($cnx);
		}
		return true;
	}

	protected function closeMreg(&$cnx) {
		if ($cnx['job']['reply']) {
			$reply = $cnx['job']['reply'];
			$reply[] = null;
			$this->IPC->routePortReply($reply);
		}

		$this->IPC->removeSocket($cnx['fd']);
		unset($this->mreg[(int)$cnx['fd']]);
		fclose($cnx['fd']);
	}

	public function checkMregConnection() {
		$c = 0;
		foreach($this->mreg as &$cnx) {
			$c++;
			if ($cnx['job'] && ($cnx['idle'] < (time()-20))) {
				// operation timeout
				$this->closeMreg($cnx);
				continue;
			}

			if (($cnx['status'] != 2) && ($cnx['idle'] < (time()-40))) {
				// timeout on connection establishement => let's forget it
				$this->closeMreg($cnx);
				continue;
			}

			if ((!$cnx['job']) && ($cnx['status'] == 2) && ($cnx['idle'] < (time()-300))) {
				// no job, and has been waiting for 300 secs, let's insert a job now :)
				$cnx['job'] = array('command' => array('operation' => 'describe'));
				$this->sendJob($cnx);
			}

			if (($c > 2) && ($cnx['status'] == 2) && ($cnx['optime'] < (time()-900))) {
				// this one has been running for long enough with no activity
				$cnx['job'] = array('command' => array('operation' => 'quit'));
				$cnx['status'] = -1;
				$this->sendJob($cnx);
			}
		}

		// now, check that we have at least 2 mregs
		if (count($this->mreg) < 2) {
			$new = 2-count($this->mreg);
			for($i = 0; $i < $new; $i++)
				$this->startMreg();
		}
		return true;
	}

	protected function parseMregPacket(&$cnx, $packet) {
		if ($cnx['status'] == 0) {
			// request login
			Logger::log(Logger::LOG_DEBUG, 'Logging in to MREG...');
			fwrite($cnx['fd'], "session\r\n-Id:".$this->localConfig['MREG']['Login']."\r\n-Password:".$this->localConfig['MREG']['Password']."\r\n.\r\n");
			$cnx['status'] = 1;
			return;
		}
		if ($cnx['status'] == 1) {
			Logger::log(Logger::LOG_DEBUG, $packet);
			if (substr($packet, 0, 3) != '200') {
				Logger::log(Logger::LOG_ERR, 'Failed to login to MREG, disconnecting...');
				$this->closeMreg($cnx);
				return;
			}
			$cnx['status'] = 2;
			$this->runQueue();
			return;
		}

		if (!$cnx['job']) return; // no job in progress?
		$cmd = $cnx['job']['command'];
		$reply = $cnx['job']['reply'];
		unset($cnx['job']);
		if (!$reply) return;

		if (isset($cmd['command'])) {
			$x = '';
			$packet = explode("\n", $packet);
			foreach($packet as $lin) {
				$lin = rtrim($lin);

				$pos = strpos($lin, '=');
				if ($pos === false) continue;
				$x .= ($x == ''?'':'&').urlencode(trim(substr($lin, 0, $pos))).'='.urlencode(trim(substr($lin, $pos+1)));
			}
			ini_set('magic_quotes_gpc', false);
			parse_str($x, $res);

			$reply[] = $res;
			$this->IPC->routePortReply($reply);

			return;
		}

		$reply[] = $packet;
		$this->IPC->routePortReply($reply);
	}

	public function mregData($id) {
		if (!isset($this->mreg[$id])) return false;

		$cnx = &$this->mreg[$id];

		$read = fgets($cnx['fd'], 8192);
		if (is_bool($read) || ($read === '')) {
			Logger::log(Logger::LOG_WARN, 'Lost connection to MREG');
			$this->closeMreg($cnx);
			return;
		}
		$cnx['buf'] .= $read;

		while(1) {
			$pos = strpos($cnx['buf'], "\r\n.\r\n");
			if ($pos === false) return;

			$packet = substr($cnx['buf'], 0, $pos);
			$cnx['buf'] = (string)substr($cnx['buf'], $pos+5);
			$this->parseMregPacket($cnx, $packet);
		}
	}

	public function mainLoop() {
		Timer::addTimer(array($this, 'checkMregConnection'), 60, $e = NULL, true);
		$this->checkMregConnection();
		parent::initMainLoop();
		$this->IPC->createPort('MREGd::Connector', $this);
		while(1) {
			$this->IPC->selectSockets(200000);
			Timer::processTimers();
		}
	}

}

