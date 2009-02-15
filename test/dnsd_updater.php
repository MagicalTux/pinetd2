<?php

class DNSd_updater {
	private $fp;
	private $buf;
	private $node;

	function __construct($peername, $ip, $secret, $port = 53) {
		// connect to host
		$this->fp = fsockopen($ip, $port, $errno, $errstr, 5);
		if (!$this->fp) throw new Exception('['.$errno.'] '.$errstr);

		$pkt = $peername.pack('N', time());
		$pkt .= sha1($pkt.$secret, true); // signature
		$pkt = 'BEGIN' . $pkt;
		fputs($this->fp, pack('n', strlen($pkt)).$pkt);

		// read reply
		$pkt = $this->readPkt();
		$signature = sha1(substr($pkt, 0, -20).$secret, true);
		if ($signature != substr($pkt, -20)) {
			fclose($this->fp);
			throw new Exception('Bad signature from server!');
		}
		$pkt = substr($pkt, 0, -20);
		list(,$stamp) = unpack('N', substr($pkt, -4));
		if (abs(time()-$stamp) > 5) throw new Exception('Invalid time synchronization between client & server');

		$node = substr($pkt, 0, -4);
		$this->node = $node;
	}

	public function getNode() {
		return $this->node;
	}

	protected function readPkt() {
		$first = true;
		while(1) {
			if ($first) {
				$first = false;
			} else {
				$this->buf .= fread($this->fp, 4096);
				if (feof($this->fp)) throw new Exception('Lost server while reading data');
			}

			if (strlen($this->buf) < 2) continue;

			list(,$len) = unpack('n', $this->buf);
			if ($len + 2 > strlen($this->buf)) continue;

			$pkt = substr($this->buf, 2, $len);
			$this->buf = (string)substr($this->buf, $len+2);

			return $pkt;
		}
	}

	protected function sendPkt($pkt) {
		$data = pack('n', strlen($pkt)) . $pkt;
		return fwrite($this->fp, $data);
	}

	public function __call($func, $args) {
		$this->sendPkt(serialize(array($func, $args)));
		$res = $this->readPkt();

		if ($res === '') return NULL;

		$res = unserialize($res);

		if ((is_object($res)) && ($res instanceof Exception)) throw $res;

		return $res;
	}
}

$dnsd = new DNSd_updater('MyPeer', '127.0.0.1', 'azerty', 10053);

echo 'Connected to '.$dnsd->getNode()."\n";

$id = $dnsd->getZone('shigoto');

if (is_null($id)) { // not found
	$id = $dnsd->createZone('shigoto');

	var_dump($dnsd->addRecord($id, '', 'A', '192.168.0.1'));
	var_dump($dnsd->addRecord($id, '', 'A', '192.168.0.2', 600));
	var_dump($dnsd->addRecord($id, '', 'NS', 'ns1'));
	var_dump($dnsd->addRecord($id, '', 'MX', array('data' => 'mail.ookoo.org.', 'mx_priority' => 10)));
	var_dump($dnsd->addRecord($id, '', 'SOA', array('data' => 'ns1.ookoo.org.', 'resp_person' => 'root', 'serial' => '2009021500', 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600)));
	var_dump($dnsd->addRecord($id, 'www', 'A', '127.0.0.1'));
	var_dump($dnsd->addRecord($id, 'test', 'CNAME', 'www'));
	var_dump($dnsd->addRecord($id, '*', 'CNAME', 'www'));
	var_dump($dnsd->addRecord($id, 'ipv6', 'AAAA', '::1'));
	var_dump($dnsd->addRecord($id, 'ipv6', 'A', '127.0.0.1'));
}

//var_dump($id);

//var_dump($dnsd->dumpZone('shigoto'));

var_dump($dnsd->createDomain('test.com', $id));
var_dump($dnsd->createDomain('test2.com', $id));
var_dump($dnsd->createDomain('test3.com', $id));

//var_dump($dnsd->deleteDomain('test2.com'));

