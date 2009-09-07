<?php

class NetBatch {
	private $fp;
	private $name;
	private $salt;
	private $type;
	private $pipes = array();
	private $pipes_buf = array();
	private $running = array();
	private $returnCode = array();
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
			'persist' => $persist,
		);

		if (!is_null($env)) $packet['env'] = $env;

		$this->sendPacket(serialize($packet), self::PKT_RUN);

		$pid = $this->readPacket();
		if ($pid === '0') return false;

		list(,$pid) = unpack('N', $pid);
		var_dump($pid);
		$this->running[$pid] = true;
		$this->last_pid = $pid;
		$this->pipes[$pid] = $pipes;
		$this->pipes_buf[$pid] = array();

		return $pid;
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

	public function read($fd, $size, $pid = NULL) {
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

			$this->getEvent();
		}
	}

	public function gets($fd, $size = NULL, $pid = NULL) {
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

			$this->getEvent();
		}
	}

	public function wait($pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;

		while($this->running[$pid])
			$this->getEvent();

		return $this->returnCode[$pid];
	}

	public function write($fd, $data, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $fd).$data, self::PKT_DATA);
	}

	public function kill($signal = 15, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $signal), self::PKT_KILL);
	}

	protected function getEvent() {
		$pkt = $this->readPacket();

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
	}

	public function close($fd, $pid = NULL) {
		if (is_null($pid)) $pid = $this->last_pid;
		$this->sendPacket(pack('NN', $pid, $fd), self::PKT_CLOSE);
	}

	protected function sendPacket($data, $type = self::PKT_STANDARD) {
		return fwrite($this->fp, pack('nn', $type, strlen($data)).$data);
	}

	protected function readPacket() {
		if (feof($this->fp)) throw new Exception('Connection lost');
		$len = fread($this->fp, 4);
		list(,$type,$len) = unpack('n2', $len);

		$this->type = $type;

		if ($len == 0) return '';

		return fread($this->fp, $len);
	}
}

$netbatch = new NetBatch('127.0.0.1');
$netbatch->ident('test','test');

echo "Running: php\n";

$netbatch->run(array('php'), NULL, array('foo' => 'bougaga!'));
$netbatch->write(0, '<?php print_r($_ENV);');
$netbatch->close(0); // close stdin

while(!$netbatch->eof(1))
	var_dump(rtrim($netbatch->gets(1)));

$netbatch->wait();
$netbatch->dump();

