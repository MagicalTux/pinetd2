<?php

namespace Daemon\NetBatch;

use pinetd\Logger;
use pinetd\Timer;

class Persist_Client extends \pinetd\ProcessChild {
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
	private $pkeys = array();
	private $msg = '';
	private $login;

	public function setLogin(array $login) {
		$this->login = $login;

		// drop privileges
		$suid = new \pinetd\SUID($login['uid'], $login['gid']);
		$suid->setIt();
	}
	
	public function childSignaled($pid, $status, $signal = NULL) {
		if (is_null($this->procs)) return false; // end of process

		// ok, a process exited!
		if (!isset($this->procs[$pid])) return true;

		// close all (remaining) fds for this process and call $this->checkPipes($pid)
		foreach($this->procs[$pid]['pipes'] as $id => $fd) {
			// Send EOF
			$this->addMsg(pack('NN', $pid, $id), self::PKT_EOF);
			fclose($fd);
		}
		$this->addMsg(pack('N', $pid), self::PKT_NOPIPES);

		$this->procs[$pid]['pipes'] = array();

		if ($this->procs[$pid]['proc'])
			proc_close($this->procs[$pid]['proc']);
		if ($this->procs[$pid]['pkey']) unset($this->pkeys[$this->procs[$pid]['pkey']]);
		unset($this->procs[$pid]);

		$this->addMsg(pack('NN', $pid, $status).((string)$signal), self::PKT_RETURNCODE);
		$this->fetchMsg($pid);

		return true;
	}

	public function _ParentIPC_doRun($null, array $pkt) {
		$cmd = $pkt['cmd'];
		$pipestmp = $pkt['pipes'];
		$cwd = $this->login['cwd'];
		$env = $pkt['env']?:array();
		$persist_key = $pkt['persist']?:false;
		if ((is_string($persist_key)) && (isset($this->pkeys[$persist_key]))) {
			$this->addMsg(pack('NN', $this->pkeys[$persist_key], 1));
			return $this->fetchMsg();
		}
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
					return $this->fetchMsg();
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
			return $this->fetchMsg();
		}

		$status = proc_get_status($proc);
		$this->addMsg(pack('NN', $status['pid'], 0));

		if (!$status['running']) {
			// wtf? already died?
			$this->addMsg(pack('N', $status['pid']), self::PKT_NOPIPES);
			$this->addMsg(pack('NN', $status['pid'], $status['exitcode']), self::PKT_RETURNCODE);
			foreach($pipes as $fd)
				fclose($fd);

			return $this->fetchMsg();
		}

		$insert = array('proc' => $proc, 'pipes' => $pipes);
		if (is_string($persist_key)) {
			$this->pkeys[$persist_key] = $status['pid'];
			$insert['pkey'] = $persist_key;
		}
		$this->procs[$status['pid']] = $insert;

		foreach($pipes as $id => $fd) {
			$extra = array($id, $status['pid'], $fd);
			$this->IPC->registerSocketWait($fd, array($this, 'handleNewData'), $extra);
			unset($extra);
		}

		return $this->fetchMsg();
	}

	protected function checkPipes($pid) {
		if ($this->procs[$pid]['pipes']) return;

		// NO MOAR PIPES!
		$this->addMsg(pack('N', $pid), self::PKT_NOPIPES);

		// check if execution completed
		if ($this->procs[$pid]['proc']) {
			$status = proc_get_status($this->procs[$pid]['proc']);
		} else {
			$status = array(
				'running' => false,
				'exitcode' => -1,
			);
		}

		if (!$status['running']) {
			if ($this->procs[$pid]['proc'])
				proc_close($this->procs[$pid]['proc']);
			if ($this->procs[$pid]['pkey']) unset($this->pkeys[$this->procs[$pid]['pkey']]);
			unset($this->procs[$pid]);

			$this->addMsg(pack('NN', $pid, $status['exitcode']), self::PKT_RETURNCODE);
		}

		$this->fetchMsg($pid);
	}

	public function _ParentIPC_handleWriteData($null, $data) {
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

	public function _ParentIPC_kill($null, $pid, $signal) {
		if (isset($this->procs[$pid])) {
			proc_terminate($this->procs[$pid]['proc'], $signal);
		}
	}

	public function _ParentIPC_handleClose($null, $buf) {
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
		$this->msg .= pack('nn', $type, strlen($msg)) . $msg;
	}

	public function _ParentIPC_fetchMsg() {
		$ret = $this->fetchMsg();
		return $ret;
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

	public function _ParentIPC_pollPid($null, $pid) {
		$tmp = $this->queue[$pid];
		unset($this->queue[$pid]);
		return $tmp;
	}

	public function mainLoop($IPC) {
		$this->IPC = $IPC;
		$this->IPC->setParent($this);
//		$this->localConfig = $this->IPC->getLocalConfig();
		$this->processBaseName = get_class($this).' for '.$this->login['login'];
		$this->setProcessStatus();

		while(1) {
			$this->IPC->selectSockets(200000);
			Timer::processTimers();
		}
	}

	public function shutdown() {
		if ($this->procs) {
			foreach($this->procs as $proc)
				proc_terminate($proc['proc'], 9);
		}
		$this->procs = NULL;
	}
}

