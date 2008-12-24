<?php

namespace Daemon\PMaild;

use pinetd\Logger;
use pinetd\SQL;
use pinetd\IPC;

class MTA extends \pinetd\Process {
	protected $agents = array();
	protected $checkPeriod = 0;
	protected $sql;
	protected $error = array();

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);

		// check tables struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		$class = relativeclass($this, 'MTA\\Storage');
		$class::validateTables($this->sql);

		// check each domain's tables struct
		$DAO = $this->sql->DAO('domains', 'domainid');
		$data = $DAO->loadByField(null);
		foreach($data as $domain) {
			$class::validateTables($this->sql, $domain->domainid);
		}
	}

	protected function launchChildsIfNeeded() {

		// check if we have any chance to load any new agent anyway?
		if (count($this->agents) >= $this->localConfig['MTA']['MaxProcesses']) return;

		// shall we check again if we need new childs ?
		if ($this->checkPeriod > time()) return;
		$this->checkPeriod = time()+5;

		// get current queue count
		$req = 'SELECT COUNT(1) FROM `mailqueue` WHERE (`next_attempt` < NOW() OR `next_attempt` IS NULL) AND `pid` IS NULL';
		$res = $this->sql->query($req);
		if (!$res) return; // ?!
		$res = $res->fetch_row();
		$count = $res[0];
		if ($count == 0) return; // no pending mail
		$to_start = floor($count/$this->localConfig['MTA']['StartThreshold']);
		// apply limits
		if ($to_start == 0) $to_start = 1;
		if ($to_start > $this->localConfig['MTA']['MaxProcesses']) $to_start = $this->localConfig['MTA']['MaxProcesses'];
		if (count($this->agents) >= $to_start) return; // already have enough
		Logger::log(Logger::LOG_DEBUG, 'Requiring '.$to_start.' daemons for handling delivery of '.$count.' mails');
		for($i=count($this->agents);$i<$to_start;$i++) $this->launchAgent();
	}

	protected function launchAgent() {
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
		$pid = pcntl_fork();
		if ($pid > 0) {
			// parent's speaking
			SQL::parentForked(); // close all sql links to avoid bugs
			fclose($pair[1]);
			$this->agents[$pid] = array(
				'pid' => $pid,
				'launch' => time(),
				'IPC' => new IPC($pair[0], false, $this),
			);
			$this->IPC->registerSocketWait($pair[0], array($this->agents[$pid]['IPC'], 'run'), $foobar = array(&$this->daemons[$pid]));
			return true;
		}
		if ($pid == 0) {
			SQL::parentForked(); // close all sql links to avoid bugs
			Timer::reset();
			fclose($pair[0]);
			$IPC = new IPC($pair[1], true, $foo = null);
			$IPC->ping();
			Logger::setIPC($IPC);
			Logger::log(Logger::LOG_DEBUG, 'MTA started with pid '.getmypid());
			$class = relativeclass($this, 'MTA_Child');
			$child = new $class($IPC);
			$child->mainLoop($IPC);
			exit;
		}
	}

	public function IPCDied($fd) {
//		$info = $this->IPC->getSocketInfo($fd);
		$this->IPC->removeSocket($fd);
//		$info = &$this->fclients[$info['pid']];
//		Logger::log(Logger::LOG_WARN, 'IPC for '.$info['pid'].' died');
	}


	public function shutdown() {
		// send stop signal to clients
		Logger::log(Logger::LOG_INFO, 'MTA stopping...');
		foreach($this->agents as $pid => $data) {
			$data['IPC']->stop();
		}
		return true;
	}

	public function mainLoop() {
		while(1) {
			$this->waitChildren();
			$this->launchChildsIfNeeded();
			$this->IPC->selectSockets(200000);
		}
	}

	public function waitChildren() {
		if (!PINETD_CAN_FORK) return;
		if (count($this->agents) == 0) return; // nothing to do
		$res = pcntl_wait($status, WNOHANG);
		if ($res == -1) return; // something bad happened
		if ($res == 0) return; // no process terminated
		// search what ended
		$ended = $this->agents[$res];
		if (is_null($ended)) return; // we do not know what ended
		if (pcntl_wifexited($status)) {
			$code = pcntl_wexitstatus($status);
			Logger::log(Logger::LOG_DEBUG, 'MTAgent with pid #'.$res.' exited');
			unset($this->agents[$res]);
			return;
		}
		if (pcntl_wifstopped($status)) {
			Logger::log(Logger::LOG_INFO, 'Waking up stopped agent on pid '.$res);
			posix_kill($res, SIGCONT);
			return;
		}
		if (pcntl_wifsignaled($status)) {
			$signal = pcntl_wtermsig($status);
			$const = get_defined_constants(true);
			$const = $const['pcntl'];
			foreach($const as $var => $val) {
				if (substr($var, 0, 3) != 'SIG') continue;
				if (substr($var, 0, 4) == 'SIG_') continue;
				if ($val != $signal) continue;
				$signal = $var;
				break;
			}
			Logger::log(Logger::LOG_INFO, 'MTAgent with pid #'.$res.' died due to signal '.$signal);
			unset($this->agents[$res]);
		}
	}
}

