<?php

namespace pinetd;

class MasterSocket {
	private $fd;

	public function __construct() {
		$this->fd = @fsockopen('unix://'.PINETD_ROOT.'/control.sock', 0);
	}

	public function connected() {
		return (bool)$this->fd;
	}

	public function stop($cb) {
		$daemons = $this->daemons();
		$seq = sha1(microtime());
		$data = array('cmd' => 'stop', 'seq' => $seq);
		$this->write($data);
		while(1) {
			$res = $this->waitSeq($seq);
			if ($res === false) break;
			call_user_func($cb, $res, $daemons);
		}
	}

	public function getVersion() {
		$seq = sha1(microtime());
		$data = array('cmd' => 'getversion', 'seq' => $seq);
		$this->write($data);
		$res = $this->waitSeq($seq);
		if ($res === false) return false;
		if ($res['type'] != 'version') return false;
		return $res['version'];
	}

	public function getPid() {
		$seq = sha1(microtime());
		$data = array('cmd' => 'getpid', 'seq' => $seq);
		$this->write($data);
		$res = $this->waitSeq($seq);
		if ($res === false) return false;
		if ($res['type'] != 'pid') return false;
		return $res['pid'];
	}

	public function daemons() {
		$seq = sha1(microtime());
		$data = array('cmd' => 'list_daemons', 'seq' => $seq);
		$this->write($data);
		$res = $this->waitSeq($seq);
		if ($res === false) return false;
		if ($res['type'] != 'daemons') return false;
		return $res['daemons'];
	}

	public function waitSeq($seq) {
		while(1) {
			$data = $this->read();
			if ($data === false) return false;
			if ($data['seq'] == $seq) return $data;
		}
	}

	private function write(array $data) {
		$data = serialize($data);
		fwrite($this->fd, pack('N', strlen($data)+4).$data);
	}

	private function read() {
		$len = fread($this->fd, 4);
		if (($len === false) || ($len === '')) return false;
		list(,$len) = unpack('N', $len);
		$data = fread($this->fd, $len-4);
		return unserialize($data);
	}
}

