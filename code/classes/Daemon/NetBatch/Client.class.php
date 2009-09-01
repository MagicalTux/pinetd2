<?php

namespace Daemon\NetBatch;
use \pinetd\Logger;

class Client extends \pinetd\TCP\Client {
	const PKT_STANDARD = 0;
	const PKT_LOGIN = 1;
	const PKT_EXIT = 2;
	const PKT_RUN = 3;
	const PKT_RETURNCODE = 4;
	const PKT_EOF = 5;
	const PKT_CLOSE = 6;
	const PKT_DATA = 7;
	const PKT_NOPIPES = 8;

	private $salt = '';
	private $login = NULL;

	private $proc = NULL;
	private $pipes;
	
	public function welcomeUser() {
		$this->setMsgEnd("\r\n");
		return true;
	}

	function sendBanner() {
		$name = $this->IPC->getName();
		$this->sendMsg($name);
		$this->salt = '';
		$saltlen = mt_rand(36,70);
		for($i = 0; $i < $saltlen; $i++)
			$this->salt .= chr(mt_rand(0,255));
		$this->sendMsg($this->salt);
	}

	public function shutdown() {
		if ($this->proc) {
			proc_close($this->proc);
			$this->proc = NULL;
		}
	}

	protected function handleRun(array $pkt) {
		$cmd = $pkt['cmd'];
		$pipestmp = $pkt['pipes'];
		$cwd = $this->login['cwd'];
		$env = $pkt['env']?:array();
		$pipes = array();

		if ($this->login['run_limit']) {
			if (!preg_match($this->login['run_limit'], $cmd)) {
				$this->sendMsg('0');
				return;
			}
		}

		foreach($pipestmp as $fd => $type) {
			$pipes[$fd] = array('pipe', $type);
		}

		if ($this->proc) {
			foreach($this->pipes as $fd) {
				$this->IPC->removeSocket($fd);
				fclose($fd);
			}
			$ret = proc_close($this->proc);

			$this->sendMsg($ret, self::PKT_RETURNCODE);
		}

		Logger::log(Logger::LOG_INFO, 'Executing: '.$cmd);

		$this->pipes = array();
		$this->proc = proc_open($cmd, $pipes, $this->pipes, $cwd, $env, array('binary_pipes' => true));
	
		if (!is_resource($this->proc)) {
			$this->proc = NULL;
			$this->sendMsg('0');
			return;
		}

		foreach($this->pipes as $id => $fd) {
			$extra = array($id, $fd);
			$this->IPC->registerSocketWait($fd, array($this, 'handleNewData'), $extra);
			unset($extra);
		}

		$this->sendMsg('1');
	}

	protected function checkPipes() {
		if ($this->pipes) return;

		// NO MOAR PIPES!
		$this->sendMsg('', self::PKT_NOPIPES);

		// check if execution completed
		$status = proc_get_status($this->proc);

		if (!$status['running']) {
			proc_close($this->proc);
			$this->proc = NULL;

			$this->sendMsg($status['exitcode'], self::PKT_RETURNCODE);
		}
	}

	protected function handleWriteData($data) {
		list(,$fd) = unpack('N', $data);
		$data = substr($data, 4);
		if (!isset($this->pipes[$fd])) return;

		fwrite($this->pipes[$fd], $data);
	}

	public function handleNewData($pipe, $fd) {
		if (feof($fd)) {
			$this->sendMsg($pipe, self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->pipes[$pipe]);
			fclose($fd);
			$this->checkPipes();
			return;
		}

		$data = fread($fd, 4096);
		if ($data === false) {
			$this->sendMsg($pipe, self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->pipes[$pipe]);
			fclose($fd);
			$this->checkPipes();
			return;
		}

		$this->sendMsg(pack('N', $pipe).$data, self::PKT_DATA);
	}

	protected function handleClose($fd) {
		if (!isset($this->pipes[$fd])) return;

		$this->sendMsg($fd, self::PKT_EOF);
		$this->IPC->removeSocket($this->pipes[$fd]);
		fclose($this->pipes[$fd]);
		unset($this->pipes[$fd]);
	}

	protected function handleLogin($buffer) {
		$login = substr($buffer, 20);
		$key = substr($buffer, 0, 20);

		$peer = $this->IPC->getRemotePeer($login);
		if (!$peer) {
			$this->sendMsg('0');
			return;
		}

		if (sha1($peer['key'].$this->salt, true) != $key) {
			$this->sendMsg('0');
			return;
		}

		$this->login = $peer;
		$this->sendMsg('1');

		Logger::log(Logger::LOG_INFO, 'User '.$peer['login'].' logged in');

		$suid = new \pinetd\SUID($peer['uid'], $peer['gid']);
		$suid->setIt();
	}

	protected function handleBuffer($buffer, $type) {
		if ($type == self::PKT_LOGIN) {
			$this->handleLogin($buffer);
			return;
		}

		if (is_null($this->login)) {
			$this->close();
			break;
		}

		switch($type) {
			case self::PKT_EXIT:
				$this->close();
				break;
			case self::PKT_RUN:
				$this->handleRun(unserialize($buffer));
				break;
			case self::PKT_CLOSE:
				$this->handleClose($buffer);
				break;
			case self::PKT_DATA:
				$this->handleWriteData($buffer);
				break;
			default:
				// TODO: log+error
				$this->close();
				break;
		}
	}

	protected function parseBuffer() {
		while($this->ok) {
			if (strlen($this->buf) < 4)
				break;

			list(,$type,$len) = unpack('n2', $this->buf);

			if ($type == self::PKT_EXIT) {
				// "exit"
				$this->close();
				break;
			}

			if (strlen($this->buf) < ($len+4))
				break;

			$this->handleBuffer(substr($this->buf, 4, $len), $type);
			$this->buf = (string)substr($this->buf, $len+4);
		}
	}

	public function sendMsg($msg, $type = self::PKT_STANDARD) {
		if (!$this->ok) return false;
		return fwrite($this->fd, pack('nn', $type, strlen($msg)) . $msg);
	}

}

