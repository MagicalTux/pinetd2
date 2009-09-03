<?php

class NetBatch {
	private $fp;
	private $name;
	private $salt;
	private $type;
	private $pipes;
	private $pipes_buf;
	private $running = false;
	private $returnCode = -1;

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

	public function run($cmd, $pipes = NULL, $env = NULL) {
		if (is_null($pipes)) 
			$pipes = array(0=>'r', 1=>'w', 2=>'w');

		$packet = array(
			'cmd' => $cmd,
			'pipes' => $pipes,
		);

		if (!is_null($env)) $packet['env'] = $env;

		$this->pipes = $pipes;
		$this->pipes_buf = array();

		$this->sendPacket(serialize($packet), self::PKT_RUN);

		$this->running = (bool)$this->readPacket();

		return $this->running;
	}

	public function dump() {
		var_dump($this->pipes_buf);
	}

	public function eof($fd) {
		// still has a buffer => not EOF
		if (isset($this->pipes_buf[$fd])) return false;

		// if the stream exists it's not EOF yet
		return !isset($this->pipes[$fd]);
	}

	public function read($fd, $size) {
		while(1) {
			if (isset($this->pipes_buf[$fd])) {
				if (strlen($this->pipes_buf[$fd]) >= $size) {
					$ret = substr($this->pipes_buf[$fd], 0, $size);
					if ($size == strlen($this->pipes_buf[$fd])) {
						unset($this->pipes_buf[$fd]);
					} else {
						$this->pipes_buf[$fd] = substr($this->pipes_buf[$fd], $size);
					}

					return $ret;
				}

				if (!isset($this->pipes[$fd])) {
					// reached EOF, flush buffer first
					$res = $this->pipes_buf[$fd];
					unset($this->pipes_buf[$fd]);
					return $res;
				}
			}

			if (!isset($this->pipes[$fd])) return false;

			$this->getEvent();
		}
	}

	public function gets($fd, $size = NULL) {
		while(1) {
			if (isset($this->pipes_buf[$fd])) {
				$pos = strpos($this->pipes_buf[$fd], "\n");

				if ($pos !== false) {
					$pos++;
					$ret = substr($this->pipes_buf[$fd], 0, $pos);
					if ($pos == strlen($this->pipes_buf[$fd])) {
						unset($this->pipes_buf[$fd]);
					} else {
						$this->pipes_buf[$fd] = substr($this->pipes_buf[$fd], $pos);
					}
					return $ret;
				}

				if ((!is_null($size)) && (strlen($this->pipes_buf[$fd]) >= $size)) {
					$ret = substr($this->pipes_buf[$fd], 0, $size);
					if ($size == strlen($this->pipes_buf[$fd])) {
						unset($this->pipes_buf[$fd]);
					} else {
						$this->pipes_buf[$fd] = substr($this->pipes_buf[$fd], $size);
					}

					return $ret;
				}

				if (!isset($this->pipes[$fd])) {
					// reached EOF, flush buffer first
					$res = $this->pipes_buf[$fd];
					unset($this->pipes_buf[$fd]);
					return $res;
				}
			}

			if (!isset($this->pipes[$fd])) return false;

			$this->getEvent();
		}
	}

	public function wait() {
		while($this->running)
			$this->getEvent();

		return $this->returnCode;
	}

	public function write($fd, $data) {
		$this->sendPacket(pack('N', $fd).$data, self::PKT_DATA);
	}

	public function kill($signal = 15) {
		$this->sendPacket($signal, self::PKT_KILL);
	}

	protected function getEvent() {
		$pkt = $this->readPacket();

		switch($this->type) {
			case self::PKT_EOF:
				unset($this->pipes[$pkt]);
				break;
			case self::PKT_DATA:
				list(,$fd) = unpack('N', substr($pkt, 0, 4));
				$pkt = (string)substr($pkt, 4);
				$this->pipes_buf[$fd] .= $pkt;
				break;
			case self::PKT_NOPIPES:
				// mmh?
				break;
			case self::PKT_RETURNCODE:
				$this->running = false;
				$this->returnCode = $pkt;
				break;
			default:
				var_dump($this->type);
				break;
		}
	}

	public function close($fd) {
		$this->sendPacket($fd, self::PKT_CLOSE);
	}

	protected function sendPacket($data, $type = self::PKT_STANDARD) {
		return fwrite($this->fp, pack('nn', $type, strlen($data)).$data);
	}

	protected function readPacket() {
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

$netbatch->run('php', NULL, array('foo' => 'bougaga!'));
$netbatch->write(0, '<?php print_r($_ENV);');
$netbatch->close(0); // close stdin

while(!$netbatch->eof(1))
	var_dump(rtrim($netbatch->gets(1)));

$netbatch->wait();
$netbatch->dump();

