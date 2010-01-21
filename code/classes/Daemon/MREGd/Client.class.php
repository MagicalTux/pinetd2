<?php

namespace Daemon\MREGd;

class Client extends \pinetd\TCP\Client {
	private $ch;
	private $id = false;
	private $random = false;

	public function welcomeUser() {
		return true;
	}

	public function sendBanner() {
		$this->setMsgEnd('');
		$this->random = '';
		for($i = 0; $i < 64; $i++) $this->random .= chr(mt_rand(0,255));
		$this->sendMsg(pack('NN', strlen($this->random)+8, 0).$this->random);
	}

	function shutdown() {
	}

	public function doResolve() {
		return;
	}

	public function handleId($packet) {
		if (!$this->IPC->checkLogin($packet['login'], $this->random, $packet['sign'])) return false;
		$this->id = true;
		return true;
	}

	protected function handlePacket($flags, $packet) {
		// handle decompression
		if ($flags & 0x80000000) $packet = \bzdecompress($packet);
		if ($flags & 0x40000000) $packet = \gzuncompress($packet);
		if ($flags & 0x20000000) $packet = \gzinflate($packet);
		// handle deserialization
		if ($flags & 0x00000008) $packet = \unserialize($packet);
		if ($flags & 0x00000004) $packet = \json_decode($packet);
		if ($flags & 0x00000002) $packet = \wddx_deserialize($packet);

		if (!$this->id) {
			$res = $this->handleId($packet);
		} else {
			try {
				$res = $this->IPC->callPort('MREGd::Connector', 'mreg', array($packet));
			} catch(\Exception $e) {
				$res = null;
			}
		}

		// handle serialization
		if ($flags & 0x00000002) $res = wddx_serialize_value($res);
		if ($flags & 0x00000004) $res = json_encode($res);
		if ($flags & 0x00000008) $res = serialize($res);
		// handle compression
		if ($flags & 0x20000000) $res = gzdeflate($res);
		if ($flags & 0x40000000) $res = gzcompress($res);
		if ($flags & 0x80000000) $res = bzcompress($res);

		$this->sendMsg(pack('NN', strlen($res)+8, $flags).$res);
	}

	protected function parseBuffer() {
		// buffer: 4 bytes (len) + 4 bytes (reserved) + serialized data
		while($this->ok) {
			if (strlen($this->buf) < 4) break;
			list(,$len) = unpack('N', $this->buf);
			if (strlen($this->buf) < $len) break;

			$pkt = substr($this->buf, 0, $len);
			$this->buf = substr($this->buf, $len);

			list(,$flags) = unpack('N', substr($pkt, 4, 4));
			$this->handlePacket($flags, substr($pkt, 8));
		}
		$this->setProcessStatus(); // back to idle
	}
}

