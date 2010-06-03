#!../php/php
<?php
date_default_timezone_set('GMT');

class PMaild2 {
	private $fd;

	public function __construct($host, $port, $uuid, $key) {
		$sock = fsockopen('ssl://'.$host, $port, $errno, $errstr, 10);
		if (!$sock) throw new \Exception('Failed to connect: '.$errstr);

		$handshake = fread($sock, 44); // remote id

		// check uuid
		$uuidb = uuid_parse($uuid);
		if (substr($handshake, 0, 16) != $uuidb) throw new \Exception('Peer didn\'t identify correctly');

		// check timestamp drift
		$stamp = unpack('N2', substr($handshake, 16, 8));
		$stamp[1] ^= $stamp[2];
		$stamp = (($stamp[1] << 32) | $stamp[2]) / 1000000;
		$offset = abs(microtime(true)-$stamp);
		if ($offset > 0.5) throw new \Exception('Time drift is over 0.5 secs ('.$offset.'), please resync servers');

		// check signature
		$sign = sha1(pack('H*', $key).substr($handshake, 0, 24), true);
		if ($sign != substr($handshake, 24)) throw new \Exception('Bad signature');

		// send our own packet
		$stamp = (int)round(microtime(true)*1000000);
		$stamp = pack('NN', (($stamp >> 32) & 0xffffffff) ^ ($stamp & 0xffffffff), $stamp & 0xffffffff);
		$handshake = str_repeat("\0", 16).$stamp;
		$handshake .= sha1(pack('H*', $key).$handshake, true);
		fwrite($sock, $handshake);
	}

	public function createStore($uuid = null) {
		$packet = array(
			'
		);
	}
}

$adm = new PMaild2('127.0.0.1',10006,'89bce390-273a-4338-af63-70a4d4c6d032','625b6355c39f4d34eba455fd20e5976c1ae1016e16e1b7ad7aae3d7db075ed60');

