<?php

class MREG_client {
	private $sock;
	private $flags = 0x40000008; // default flags for outgoing: gzip+serialize()

	public function __construct($host, $login, $key, $port = 996) {
		$this->sock = fsockopen($host, $port);

		$random = $this->readPacket();
		$sign = sha1($random.$key, true);

		if (!$this->_send(array('login' => $login, 'sign' => $sign)))
			throw new Exception('Login failed!');
	}

	public function query($packet) {
		return $this->_send($packet);
	}

	protected function _send($packet, $flags = NULL) {
		if (is_null($flags)) $flags = $this->flags;
		$packet = $this->encode($packet, $flags);
		fwrite($this->sock, pack('NN', strlen($packet)+8, $flags).$packet);
		return $this->readPacket();
	}

	protected function readPacket() {
		list(,$len,$flags) = unpack('N2', fread($this->sock, 8));
		$len -= 8;
		$data = fread($this->sock, $len);
		return $this->decode($data, $flags);
	}

	protected function decode($packet, $flags) {
		// handle decompression
		if ($flags & 0x80000000) $packet = bzdecompress($packet);
		if ($flags & 0x40000000) $packet = gzuncompress($packet);
		if ($flags & 0x20000000) $packet = gzinflate($packet);
		// handle deserialization
		if ($flags & 0x00000008) $packet = unserialize($packet);
		if ($flags & 0x00000004) $packet = json_decode($packet);
		if ($flags & 0x00000002) $packet = wddx_deserialize($packet);

		return $packet;
	}

	protected function encode($res, $flags) {
		// handle serialization
		if ($flags & 0x00000002) $res = wddx_serialize_value($res);
		if ($flags & 0x00000004) $res = json_encode($res);
		if ($flags & 0x00000008) $res = serialize($res);
		// handle compression
		if ($flags & 0x20000000) $res = gzdeflate($res);
		if ($flags & 0x40000000) $res = gzcompress($res);
		if ($flags & 0x80000000) $res = bzcompress($res);
		
		return $res;
	}
}

$mreg = new MREG_client('ws.uid.st','mutumsigillum','z198#CApoc98');
var_dump($mreg->query(array('operation' => 'describe')));

var_dump($mreg->query(array('command' => 'QueryMobileAccountList', 'wide' => 1)));

//var_dump($mreg->query(array('command' => 'CheckDomains', 'domain0' => 'legende.net', 'domain1' => 'magicaltux.biz', 'domain2' => 'fofezufzeuifzefze.com')));

//var_dump($mreg->query(array('command' => 'StatusDomainTransfer', 'domain' => 'geekstuff4you.com')));
//var_dump($mreg->query(array('command' => 'StatusDomainTransfer', 'domain' => 'magicaltux.net')));

//var_dump($mreg->query(array('command' => 'ActivateTransfer', 'domain' => 'geekstuff4you.com', 'action' => 'REQUEST', 'trigger' => '7151102')));

