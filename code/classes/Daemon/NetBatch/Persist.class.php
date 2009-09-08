<?php

namespace Daemon\NetBatch;

use pinetd\Timer;
use pinetd\Logger;

class Persist extends \pinetd\Process {
	const PKT_STANDARD = 0;
	const PKT_LOGIN = 1;
	const PKT_EXIT = 2;
	const PKT_RUN = 3;
	const PKT_RETURNCODE = 4;
	const PKT_EOF = 5;
	const PKT_CLOSE = 6;
	const PKT_DATA = 7;
	const PKT_NOPIPES = 8;
	const PKT_KILL = 9;

	private $procs = array();
	private $queue = array();
	private $msg = '';
	
	public function processWait() {
		if (is_null($this->procs)) return false; // end of process

		$pid = pcntl_wait($status, WNOHANG);
		if ($pid <= 0) return true;

		// ok, a process exited!
		if (!isset($this->procs[$pid])) return true;

		proc_close($this->procs[$pid]['proc']);
		unset($this->procs[$pid]);

		$this->addMsg(pack('NN', $pid, $status), self::PKT_RETURNCODE);
		$this->fetchMsg($pid);

		return true;
	}

	public function run(array $pkt, array $login) {
		var_dump($pkt, $login);
		return false;
		$cmd = $pkt['cmd'];
		$pipestmp = $pkt['pipes'];
		$cwd = $this->login['cwd'];
		$env = $pkt['env']?:array();
		$persist = $pkt['persist']?:false;
		$pipes = array();

		// TODO: if persist, pass run request to process thread so the process will
		// persist even when connection is closed

		// cleanup $cmd if needed
		if (!is_array($cmd)) {
			$cmd = explode(' ',$cmd);
			foreach($cmd as &$val) {
				if ($val[0] == '\'')
					$val = stripslashes(substr($val, 1, -1));
			}
			unset($val);
		}

		$final_cmd = '';

		foreach($cmd as $val) {
			if (($final_cmd == '') && ($this->login['run_limit'])) {
				if (!preg_match($this->login['run_limit'], $val)) {
					$this->addMsg('0');
					return;
				}
			}
			$final_cmd .= escapeshellarg($val).' ';
		}

		foreach($pipestmp as $fd => $type) {
			$pipes[$fd] = array('pipe', $type);
		}

		Logger::log(Logger::LOG_INFO, 'Executing: '.$final_cmd);

		$fpipes = array();
		$proc = proc_open($final_cmd, $pipes, $fpipes, $cwd, $env, array('binary_pipes' => true));
		$pipes = $fpipes;
	
		if (!is_resource($proc)) {
			$this->addMsg('0');
			return;
		}

		$status = proc_get_status($proc);
		$this->addMsg(pack('N', $status['pid']));

		if (!$status['running']) {
			// wtf? already died?
			$this->addMsg(pack('N', $status['pid']), self::PKT_NOPIPES);
			$this->addMsg(pack('NN', $status['pid'], $status['exitcode']), self::PKT_RETURNCODE);
			foreach($pipes as $fd)
				fclose($fd);

			return;
		}

		$this->procs[$status['pid']] = array('proc' => $proc, 'pipes' => $pipes);

		foreach($pipes as $id => $fd) {
			$extra = array($id, $status['pid'], $fd);
			$this->IPC->registerSocketWait($fd, array($this, 'handleNewData'), $extra);
			unset($extra);
		}
	}

	protected function checkPipes($pid) {
		if ($this->procs[$pid]['pipes']) return;

		// NO MOAR PIPES!
		$this->addMsg(pack('N', $pid), self::PKT_NOPIPES);

		// check if execution completed
		$status = proc_get_status($this->procs[$pid]['proc']);

		if (!$status['running']) {
			proc_close($this->procs[$pid]['proc']);
			unset($this->procs[$pid]);

			$this->addMsg(pack('NN', $pid, $status['exitcode']), self::PKT_RETURNCODE);
		}

		$this->fetchMsg($pid);
	}

	protected function handleWriteData($data) {
		list(,$pid,$fd) = unpack('N2', $data);
		$data = substr($data, 8);
		if (!isset($this->procs[$pid]['pipes'][$fd])) return;

		fwrite($this->procs[$pid]['pipes'][$fd], $data);
		fflush($this->procs[$pid]['pipes'][$fd]);
	}

	public function handleNewData($pipe, $pid, $fd) {
		if (feof($fd)) {
			$this->addMsg(pack('NN', $pid, $pipe), self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->procs[$pid]['pipes'][$pipe]);
			fclose($fd);
			$this->checkPipes($pid);
			$this->fetchMsg($pid);
			return;
		}

		$data = fread($fd, 4096);
		if ($data === false) {
			$this->addMsg(pack('NN', $pid, $pipe), self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->procs[$pid]['pipes'][$pipe]);
			fclose($fd);
			$this->checkPipes($pid);
			$this->fetchMsg($pid);
			return;
		}

		$this->addMsg(pack('NN', $pid, $pipe).$data, self::PKT_DATA);
		$this->fetchMsg($pid);
	}

	protected function handleClose($buf) {
		list(, $pid, $fd) = unpack('N2', $buf);
		if (!isset($this->procs[$pid]['pipes'][$fd])) return;
		$pipe = $this->procs[$pid]['pipes'][$fd];

		$this->addMsg(pack('NN', $pid, $fd), self::PKT_EOF);
		$this->IPC->removeSocket($pipe);
		fclose($pipe);
		unset($this->procs[$pid]['pipes'][$fd]);

		$this->fetchMsg($pid);
	}

	public function addMsg($msg, $type = self::PKT_STANDARD) {
		if (!$this->ok) return false;
		$this->msg .= pack('nn', $type, strlen($msg)) . $msg;
	}

	public function fetchMsg($pid = NULL) {
		$tmp = $this->msg;
		$this->msg = '';
		if (!is_null($pid)) {
			$this->queue[$pid] .= $tmp;
			return true;
		}
		return $tmp;
	}

	public function mainLoop() {
		parent::initMainLoop();
		Timer::addTimer(array($this, 'processWait'), 0.2, $e = null, true);
		$this->IPC->createPort('NetBatch::Persist', $this);

		while(1) {
			$this->IPC->selectSockets(200000);
			Timer::processTimers();
		}
	}

	public function shutdown() {
		if ($this->procs) {
			foreach($this->procs as $proc)
				proc_close($proc['proc']);
		}
		$this->procs = NULL;
	}
}

