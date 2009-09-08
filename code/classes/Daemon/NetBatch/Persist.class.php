<?php

namespace Daemon\NetBatch;

use pinetd\Logger;
use pinetd\IPC;
use pinetd\SQL;
use pinetd\Timer;

class Persist extends \pinetd\Process {
	protected $runners = array();
	protected $run_login = array();

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
	}

	public function run(array $pkt, array $login) {
		$IPC = $this->getRunner($login);
		return $IPC->doCall('doRun', array($pkt));
	}

	public function poll($pid, array $login) {
		$IPC = $this->getRunner($login);
		return $IPC->doCall('pollPid', array($pid));
	}

	public function proxy($pid, array $login, $func, array $args) {
		$IPC = $this->getRunner($login);
		$IPC->doCall($func, $args);
		return $IPC->doCall('pollPid', array($pid));
	}

	protected function getRunner(array $login) {
		if (isset($this->run_login[$login['login']])) {
			$pid = $this->run_login[$login['login']];
			if (isset($this->runners[$pid])) {
				return $this->runners[$pid]['IPC'];
			}
		}
		$this->launchRunner($login);
		if (isset($this->run_login[$login['login']])) {
			$pid = $this->run_login[$login['login']];
			if (isset($this->runners[$pid])) {
				return $this->runners[$pid]['IPC'];
			}
		}
		throw new Exception('Failed to start runner!');
	}

	protected function launchRunner(array $login) {
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		$pid = pcntl_fork();
		if ($pid > 0) {
			// parent's speaking
			SQL::parentForked(); // close all sql links to avoid bugs
			fclose($pair[1]);
			$this->runners[$pid] = array(
				'pid' => $pid,
				'launch' => time(),
				'IPC' => new IPC($pair[0], false, $this, $this->IPC),
			);
			$this->run_login[$login['login']] = $pid;
			$this->IPC->registerSocketWait($pair[0], array($this->runners[$pid]['IPC'], 'run'), $foobar = array(&$this->runners[$pid]));
			usleep(50000); // give time for the child to init
			return true;
		}
		if ($pid == 0) {
			SQL::parentForked(); // close all sql links to avoid bugs
			Timer::reset();
			fclose($pair[0]);
			$IPC = new IPC($pair[1], true, $foo = null, $this->IPC);
			Logger::setIPC($IPC);
			Logger::log(Logger::LOG_DEBUG, 'Persistent runner started with pid '.getmypid());
			$class = relativeclass($this, 'Persist_Client');
			$child = new $class($IPC);
			$child->setLogin($login);
			$child->mainLoop($IPC);
			exit;
		}
	}

	public function IPCDied($fd) {
		$info = $this->IPC->getSocketInfo($fd);
		$this->IPC->removeSocket($fd);
		unset($this->runners[$info['pid']]);
//		$info = &$this->fclients[$info['pid']];
//		Logger::log(Logger::LOG_WARN, 'IPC for '.$info['pid'].' died');
	}


	public function shutdown() {
		// send stop signal to clients
		Logger::log(Logger::LOG_INFO, 'MTA stopping...');
		foreach($this->runners as $pid => $data) {
			$data['IPC']->stop();
		}
		return true;
	}

	public function mainLoop() {
		parent::initMainLoop();
		$this->IPC->createPort('NetBatch::Persist', $this);
		while(1) {
			$this->IPC->selectSockets(200000);
		}
	}

	public function childSignaled($res, $status, $signal = NULL) {
		if (count($this->runners) == 0) return; // nothing to do
		// search what ended
		$ended = $this->runners[$res];
		if (is_null($ended)) return; // we do not know what ended

		if (is_null($signal)) {
			Logger::log(Logger::LOG_DEBUG, 'Process runner with pid #'.$res.' exited');
		} else {
			Logger::log(Logger::LOG_INFO, 'Process runner with pid #'.$res.' died due to signal '.$signal);
		}
		unset($this->runners[$res]);
	}
}

