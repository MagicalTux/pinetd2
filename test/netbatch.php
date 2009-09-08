<?php

class NetBatch_Process {
	private $pid;
	private $parent;
	private $blocking;
	private $resume;

	public function __construct($parent, $pid, $resume, $persist) {
		$this->parent = $parent;
		$this->pid = $pid;
		$this->blocking = true;
		$this->resume = $resume;
		$this->persist = $persist;
	}

	public function __destruct() {
		if ($this->running())
			$this->kill(9); // SIGKILL
		$this->parent->freePid($this->pid);
	}

	public function setBlocking($blocking) {
		$this->blocking = (bool)$blocking;
	}

	public function dump() {
		return $this->parent->dump($this->pid);
	}

	public function eof($fd) {
		return $this->parent->eof($fd, $this->pid);
	}

	public function read($fd, $size) {
		return $this->parent->read($fd, $size, $this->pid, $this->blocking);
	}

	public function gets($fd, $size = NULL) {
		return $this->parent->gets($fd, $size, $this->pid, $this->blocking);
	}

	public function wait() {
		return $this->parent->wait($this->pid);
	}

	public function write($fd, $buf) {
		return $this->parent->write($fd, $buf, $this->pid);
	}

	public function kill($signal = 15) {
		return $this->parent->kill($signal, $this->pid);
	}

	public function close($fd) {
		return $this->parent->close($fd, $this->pid);
	}

	public function getPid() {
		return $this->pid;
	}

	public function poll() {
		return $this->parent->poll($this->pid);
	}

	public function isResumed() {
		return (bool)$this->resume;
	}

	public function running() {
		return $this->parent->isRunning($this->pid);
	}

	public function exitCode() {
		return $this->parent->getExitCode($this->pid);
	}
}

class NetBatch {
	private $fp;
	private $name;
	private $salt;
	private $type;
	private $pipes = array();
	private $pipes_buf = array();
	private $running = array();
	private $returnCode = array();
	private $persist = array();
	private $last_pid;

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

	public function __construct($host, $port = 65432) {
		$this->fp = fsockopen($host, $port);
		if (!$this->fp) throw new Exception('Could not connect');

		$this->name = $this->readPacket();
		$this->salt = $this->readPacket();
	}

	public function __destruct() {
		$this->sendPacket('', self::PKT_EXIT);
		fclose($this->fp);
	}

	public function freePid($pid) {
		unset($this->pipes[$pid]);
		unset($this->pipes_buf[$pid]);
		unset($this->returnCode[$pid]);
	}

	public function ident($login, $key) {
		$this->sendPacket(sha1($key.$this->salt, true).$login, self::PKT_LOGIN);
		$result = $this->readPacket();
		return (bool)$result;
	}

	public function run($cmd, $pipes = NULL, $env = NULL, $persist = false) {
		if (is_null($pipes)) 
			$pipes = array(0=>'r', 1=>'w', 2=>'w');

		$packet = array(
			'cmd' => $cmd,
			'pipes' => $pipes,
		);
		if ($persist !== false)
			$packet['persist'] = $persist;

		if (!is_null($env)) $packet['env'] = $env;

		$this->sendPacket(serialize($packet), self::PKT_RUN);

		$pid = $this->readPacket();
		if ($pid === '0') return false;

		list(,$pid, $resume) = unpack('N2', $pid);
		$this->running[$pid] = true;
		$this->last_pid = $pid;
		$this->pipes[$pid] = $pipes;
		$this->pipes_buf[$pid] = array();
		if ($persist !== false) $this->persist[$pid] = true;

		return new NetBatch_Process($this, $pid, $resume, $persist);
	}

	public function dump($pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		var_dump($this->pipes_buf[$pid]);
	}

	public function eof($fd, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		// still has a buffer => not EOF
		if (isset($this->pipes_buf[$pid][$fd])) return false;

		// if the stream exists it's not EOF yet
		return !isset($this->pipes[$pid][$fd]);
	}

	public function read($fd, $size, $pid = NULL, $blocking) {
		if (is_null($pid)) 
			$pid = $this->last_pid;

		while(1) {
			if (isset($this->pipes_buf[$pid][$fd])) {
				if (strlen($this->pipes_buf[$pid][$fd]) >= $size) {
					$ret = substr($this->pipes_buf[$pid][$fd], 0, $size);
					if ($size == strlen($this->pipes_buf[$pid][$fd])) {
						unset($this->pipes_buf[$pid][$fd]);
					} else {
						$this->pipes_buf[$pid][$fd] = substr($this->pipes_buf[$pid][$fd], $size);
					}

					return $ret;
				}

				if (!isset($this->pipes[$pid][$fd])) {
					// reached EOF, flush buffer first
					$res = $this->pipes_buf[$pid][$fd];
					unset($this->pipes_buf[$pid][$fd]);
					return $res;
				}
			}

			if (!isset($this->pipes[$pid][$fd])) return false;

			if (!$this->getEvent($pid, $blocking)) break;
		}
		return false;
	}

	public function gets($fd, $size = NULL, $pid = NULL, $blocking) {
		if (is_null($pid))
			$pid = $this->last_pid;

		while(1) {
			if (isset($this->pipes_buf[$pid][$fd])) {
				$pos = strpos($this->pipes_buf[$pid][$fd], "\n");

				if ($pos !== false) {
					$pos++;
					$ret = substr($this->pipes_buf[$pid][$fd], 0, $pos);
					if ($pos == strlen($this->pipes_buf[$pid][$fd])) {
						unset($this->pipes_buf[$pid][$fd]);
					} else {
						$this->pipes_buf[$pid][$fd] = substr($this->pipes_buf[$pid][$fd], $pos);
					}
					return $ret;
				}

				if ((!is_null($size)) && (strlen($this->pipes_buf[$pid][$fd]) >= $size)) {
					$ret = substr($this->pipes_buf[$pid][$fd], 0, $size);
					if ($size == strlen($this->pipes_buf[$pid][$fd])) {
						unset($this->pipes_buf[$pid][$fd]);
					} else {
						$this->pipes_buf[$pid][$fd] = substr($this->pipes_buf[$pid][$fd], $size);
					}

					return $ret;
				}

				if (!isset($this->pipes[$pid][$fd])) {
					// reached EOF, flush buffer first
					$res = $this->pipes_buf[$pid][$fd];
					unset($this->pipes_buf[$pid][$fd]);
					return $res;
				}
			}

			if (!isset($this->pipes[$pid][$fd])) return false;

			if (!$this->getEvent($pid, $blocking)) break;
		}
		return false;
	}

	public function wait($pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;

		while($this->running[$pid])
			$this->getEvent($pid);

		return $this->returnCode[$pid];
	}

	public function getExitCode($pid) {
		return $this->returnCode[$pid];
	}

	public function isRunning($pid) {
		return isset($this->running[$pid]);
	}

	public function hasProcesses() {
		return (bool)count($this->running);
	}

	public function write($fd, $data, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $fd).$data, self::PKT_DATA);
	}

	public function kill($signal = 15, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $signal), self::PKT_KILL);
	}

	public function poll($pid) {
		$this->sendPacket(pack('N', $pid), self::PKT_POLL);
	}

	protected function getEvent($pid, $blocking = true) {
		$pkt = $this->readPacket($pid, $blocking);

		if ($pkt === false) return false;

		switch($this->type) {
			case self::PKT_EOF:
				list(,$pid, $fd) = unpack('N2', $pkt);
				unset($this->pipes[$pid][$fd]);
				break;
			case self::PKT_DATA:
				list(,$pid,$fd) = unpack('N2', substr($pkt, 0, 8));
				$pkt = (string)substr($pkt, 8);
				$this->pipes_buf[$pid][$fd] .= $pkt;
				break;
			case self::PKT_NOPIPES:
				// mmh?
				break;
			case self::PKT_RETURNCODE:
				list(,$pid, $rc) = unpack('N2', $pkt);
				unset($this->running[$pid]);
				$this->returnCode[$pid] = $rc;
				break;
			default:
				var_dump($this->type);
				break;
		}
		return true;
	}

	public function getActive(array $tmp) {
		if (!$tmp)
			throw new Exception('getActive() called without params');

		$this->getEvent(0, false);

		$list = array();
		$keys = array();
		foreach($tmp as $key => $process) {
			$list[$process->getPid()] = $process;
			$keys[$process->getPid()] = $key;
		}

		$final_list = array();
		while(!$final_list) {
			foreach($list as $pid => $process) {
				if ($this->pipes_buf[$pid]) {
					$final_list[$keys[$pid]] = $process;
				}
				
				if (!$process->running())
					$final_list[$keys[$pid]] = $process;
			}

			if (!$final_list)
				$this->getEvent(0);
		}

		return $final_list;
	}

	public function close($fd, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $fd), self::PKT_CLOSE);
	}

	protected function sendPacket($data, $type = self::PKT_STANDARD) {
		return fwrite($this->fp, pack('nn', $type, strlen($data)).$data);
	}

	protected function readPacket($pid = 0, $blocking = true) {
		if (feof($this->fp)) throw new Exception('Connection lost');

		if ($this->persist[$pid]) {
			$now = time();
			if ($this->persist[$pid] != $now) {
				$this->poll($pid);
				$this->persist[$pid] = $now;
			}
			if ($blocking)
				while (!stream_select($r = array($this->fp), $w = NULL, $e = NULL, 1)) {
					$this->poll($pid);
				}
		}

		if (!$blocking) {
			if (!stream_select($r = array($this->fp), $w = NULL, $e = NULL, 0))
				return false;
		}

		$len = fread($this->fp, 4);
		if (strlen($len) != 4) throw new Exception('Connection lost');
		list(,$type,$len) = unpack('n2', $len);

		$this->type = $type;

		if ($len == 0) return '';

		return fread($this->fp, $len);
	}
}

$netbatch = new NetBatch('127.0.0.1');
$netbatch->ident('test','test');

$process = $netbatch->run(array('php'), NULL, NULL, 'PhpEval');

if (!$process->isResumed()) {
	$process->write(0, '<?php while(1) { echo "BLAH\n"; sleep(1); }');
	$process->close(0);
}

$c = 10;

while(!$process->eof(1)) {
	var_dump(rtrim($process->gets(1)));
	if ($c-- < 0) {
		$process->kill();
		$c = 99;
	}
}

while(!$process->eof(2))
	var_dump(rtrim($process->gets(2)));

/*
$ping = array();

$ping[] = $netbatch->run(array('ping', '127.0.0.1', '-c', '5'));
$ping[] = $netbatch->run(array('ping', 'google.fr', '-c', '5'));

foreach($ping as $process)
	$process->setBlocking(false);

while($netbatch->hasProcesses()) {
	foreach($netbatch->getActive($ping) as $key => $process) {
		$line = $process->gets(1);
		while($line !== false) {
			echo 'PID#'.$process->getPid().': '.rtrim($line)."\n";
			$line = $process->gets(1);
		}

		if (!$process->running()) {
			echo 'PID#'.$process->getPid().' has exited with exit code '.$process->exitCode()."\n";
			unset($ping[$key]);
		}
	}
}
*/

/*
echo "Running: php\n";

$process = $netbatch->run(array('php'), NULL, array('foo' => 'bougaga!'));
$process->write(0, '<?php print_r($_ENV);');
$process->close(0); // close stdin

while(!$process->eof(1))
	var_dump(rtrim($process->gets(1)));

$process->wait();
$process->dump();
*/

