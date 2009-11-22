<?php

namespace Daemon\NetBatch;
use \pinetd\Logger;
use \pinetd\Timer;

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
	const PKT_KILL = 9;
	const PKT_POLL = 10;

	private $salt = '';
	private $login = NULL;

	private $procs = array();
	
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

	public function childSignaled($pid, $status, $signal = NULL) {
		if (is_null($this->procs)) return false; // end of process

		// ok, a process exited!
		if (!isset($this->procs[$pid])) return true;

		proc_close($this->procs[$pid]['proc']);
		unset($this->procs[$pid]);

		$this->sendMsg(pack('NN', $pid, $status).((string)$signal), self::PKT_RETURNCODE);

		return true;
	}

	public function shutdown() {
		if ($this->procs) {
			foreach($this->procs as $proc)
				proc_close($proc['proc']);
		}
		$this->procs = NULL;
	}

	protected function handleRun(array $pkt) {
		if (isset($pkt['persist'])) {
			// ok, let's transmit this to the persist engine
			$res = $this->IPC->callPort('NetBatch::Persist', 'run', array($pkt, $this->login));
			if (!$res) {
				$this->sendMsg('0');
				return;
			}
			$this->sendRawMsg($res);
			return;
		}
		$cmd = $pkt['cmd'];
		$pipestmp = $pkt['pipes'];
		$cwd = $this->login['cwd'];
		$env = $pkt['env']?:array();
		$pipes = array();

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
					$this->sendMsg('0');
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
			$this->sendMsg('0');
			return;
		}

		$status = proc_get_status($proc);
		$this->sendMsg(pack('NN', $status['pid'], 0));

		if (!$status['running']) {
			// wtf? already died?
			$this->sendMsg(pack('N', $status['pid']), self::PKT_NOPIPES);
			$this->sendMsg(pack('NN', $status['pid'], $status['exitcode']), self::PKT_RETURNCODE);
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
		$this->sendMsg(pack('N', $pid), self::PKT_NOPIPES);

		// check if execution completed
		$status = proc_get_status($this->procs[$pid]['proc']);

		if (!$status['running']) {
			proc_close($this->procs[$pid]['proc']);
			unset($this->procs[$pid]);

			$this->sendMsg(pack('NN', $pid, $status['exitcode']), self::PKT_RETURNCODE);
		}
	}

	protected function handleWriteData($data) {
		$odata = $data;
		list(,$pid,$fd) = unpack('N2', $data);
		$data = substr($data, 8);
		if (!isset($this->procs[$pid]['pipes'][$fd]))
			return $this->proxy($pid, 'handleWriteData', array($odata));

		fwrite($this->procs[$pid]['pipes'][$fd], $data);
		fflush($this->procs[$pid]['pipes'][$fd]);
	}

	public function handleNewData($pipe, $pid, $fd) {
		if (feof($fd)) {
			$this->sendMsg(pack('NN', $pid, $pipe), self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->procs[$pid]['pipes'][$pipe]);
			fclose($fd);
			$this->checkPipes($pid);
			return;
		}

		$data = fread($fd, 4096);
		if ($data === false) {
			$this->sendMsg(pack('NN', $pid, $pipe), self::PKT_EOF);
			$this->IPC->removeSocket($fd);
			unset($this->procs[$pid]['pipes'][$pipe]);
			fclose($fd);
			$this->checkPipes($pid);
			return;
		}

		$this->sendMsg(pack('NN', $pid, $pipe).$data, self::PKT_DATA);
	}

	protected function proxy($pid, $func, array $args) {
		$res = $this->IPC->callPort('NetBatch::Persist', 'proxy', array($pid, $this->login, $func, $args));
		$this->sendRawMsg($res);
	}

	protected function handleClose($buf) {
		list(, $pid, $fd) = unpack('N2', $buf);
		if (!isset($this->procs[$pid]['pipes'][$fd]))
			return $this->proxy($pid, 'handleClose', array($buf));
		$pipe = $this->procs[$pid]['pipes'][$fd];

		$this->sendMsg(pack('NN', $pid, $fd), self::PKT_EOF);
		$this->IPC->removeSocket($pipe);
		fclose($pipe);
		unset($this->procs[$pid]['pipes'][$fd]);
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
			case self::PKT_POLL:
				list(,$pid) = unpack('N', $buffer);
				$res = $this->IPC->callPort('NetBatch::Persist', 'poll', array($pid, $this->login));
				$this->sendRawMsg($res);
				break;
			case self::PKT_KILL:
				list(,$pid,$signal) = unpack('N2', $buffer);
				if ($this->proc[$pid])
					proc_terminate($this->proc[$pid], $signal);
				else
					$this->proxy($pid, 'kill', array($pid, $signal));
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

			if (strlen($this->buf) < ($len+4)) {
				break;
			}

			$tmp = substr($this->buf, 4, $len);

			$this->buf = (string)substr($this->buf, $len+4);
			$this->handleBuffer($tmp, $type);
		}
	}

	public function sendRawMsg($msg) {
		fwrite($this->fd, $msg);
	}

	public function sendMsg($msg, $type = self::PKT_STANDARD) {
		if (!$this->ok) return false;
		$n = fwrite($this->fd, pack('nn', $type, strlen($msg)) . $msg);
		fflush($this->fd);
		return $n;
	}

}

