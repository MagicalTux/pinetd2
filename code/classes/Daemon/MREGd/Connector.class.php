<?php

namespace Daemon\MREGd;

use pinetd\Logger;
use pinetd\IPC;
use pinetd\Timer;

class Connector extends \pinetd\Process {
	private $mreg;
	private $mreg_status;
	private $mreg_ping;
	private $mreg_buf;

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
	}

	public function IPCDied($fd) {
		$info = $this->IPC->getSocketInfo($fd);
		$this->IPC->removeSocket($fd);
		unset($this->runners[$info['pid']]);
//		$info = &$this->fclients[$info['pid']];
//		Logger::log(Logger::LOG_WARN, 'IPC for '.$info['pid'].' died');
	}

	public function mreg($cmd) {
		if ($this->mreg_status != 2) return NULL; // not ready
		if ($this->mreg_buf != '') return NULL; // buffer not parsed yet
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
		fwrite($this->mreg, $cmdstr.".\r\n");

		if (isset($cmd['command'])) {
			$x = '';
			while(1) {
				$lin = rtrim(fgets($this->mreg, 4096));
				if ($lin == '.') break;

				$pos = strpos($lin, '=');
				if ($pos === false) continue;
				$x .= ($x == ''?'':'&').urlencode(trim(substr($lin, 0, $pos))).'='.urlencode(trim(substr($lin, $pos+1)));
			}
			ini_set('magic_quotes_gpc', false);
			parse_str($x, $res);
			return $res;
		}

		$res = '';
		while(1) {
			$lin = fgets($this->mreg, 4096);
			if (rtrim($lin) == '.') break;
			$res .= $lin;
		}

		return $res;
	}

	public function shutdown() {
		// send stop signal to clients
		Logger::log(Logger::LOG_INFO, 'MREG connector stopping...');
		$this->mreg(array('operation' => 'quit'));
		$this->mreg_status = -1;
		return true;
	}

	public function checkMregConnection() {
		if ($this->mreg_status == -1) return false;
		if ($this->mreg) {
			// TODO: check for idle time (300 secs)
			if ($this->mreg_ping < time()) {
				$this->mreg(array('operation' => 'describe')); // "ping"
				$this->mreg_ping = time()+300;
			}
			return true;
		}
		Logger::log(Logger::LOG_INFO, 'Connecting to MREG server...');
		$this->mreg = stream_socket_client('tls://'.$this->localConfig['MREG']['Host'].':'.$this->localConfig['MREG']['Port'], $errno, $errstr, 10);
		if (!$this->mreg) {
			Logger::log(Logger::LOG_WARN, 'Could not connect!! :(');
			return true;
		}

		$this->mreg_status = 0; // 0 = no connection handshake yet, waiting for input
		$this->mreg_ping = time()+45;
		$this->mreg_buf = '';
		$this->IPC->registerSocketWait($this->mreg, array($this, 'mregData'), $e=array());
		return true;
	}

	protected function parseMregPacket($packet) {
		if ($this->mreg_status == 0) {
			// request login
			Logger::log(Logger::LOG_DEBUG, 'Logging in to MREG...');
			fwrite($this->mreg, "session\r\n-Id:".$this->localConfig['MREG']['Login']."\r\n-Password:".$this->localConfig['MREG']['Password']."\r\n.\r\n");
			$this->mreg_status = 1;
			return;
		}
		if ($this->mreg_status == 1) {
			Logger::log(Logger::LOG_DEBUG, $packet);
			if (substr($packet, 0, 3) != '200') {
				Logger::log(Logger::LOG_ERR, 'Failed to login to MREG, disabling...');
				$this->mreg_status = -1;
				fclose($this->mreg);
				$this->mreg = NULL;
				return;
			}
			$this->mreg_status = 2;
			return;
		}
		var_dump($packet);
	}

	public function mregData() {
		$read = fread($this->mreg, 4096);
		if (is_bool($read) || ($read === '')) {
			Logger::log(Logger::LOG_WARN, 'Lost connection to MREG, reconnecting...');
			fclose($this->mreg);
			$this->mreg = false;
			$this->checkMregConnection();
			return;
		}
		$this->mreg_buf .= $read;

		while(1) {
			$pos = strpos($this->mreg_buf, "\r\n.\r\n");
			if ($pos === false) return;

			$packet = substr($this->mreg_buf, 0, $pos);
			$this->mreg_buf = (string)substr($this->mreg_buf, $pos+5);
			$this->parseMregPacket($packet);
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

