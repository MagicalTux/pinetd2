<?php

namespace Daemon\DNSd;

use pinetd\Logger;
use pinetd\SQL;

class TCP_Slave extends \pinetd\TCP\Client {
	protected $sql;

	public function welcomeUser() {
		$this->setMsgEnd('');
		return true; // nothing to say
	}

	public function sendBanner() {
		$localConfig = $this->IPC->getLocalConfig();
		$this->sql = SQL::Factory($localConfig['Storage']);
	}

	public function _ParentIPC_dispatch($daemon, $table, $key, $data) {
		$this->sendReply(serialize(array($table, $data)));
	}

	protected function _slave_DoSync(array $p) {
		$ts = $p[0];
		if ($ts > 0) {
			$ts = $this->sql->Timestamp($ts);
			$where = ' WHERE `changed` >= '.$this->sql->quote_escape($ts);
		} else {
			$where = '';
		}
		// now, we need to send data back!
		foreach(array('deletions', 'zones', 'zone_records', 'domains') as $table) {
			// select everything sorted by "changed", and more recent than client's database
			$req = 'SELECT * FROM `'.$table.'`'.$where.' ORDER BY `changed` ASC';
			$res = $this->sql->query($req);

			while($row = $res->fetch_assoc()) {
				$this->sendReply(serialize(array($table, $row)));
			}
		}
	}

	protected function receivePacket($pkt) {
		$data = unserialize($pkt);
		$func = '_slave_'.$data[0];
		$res = $this->$func($data[1]);
//		$this->sendReply(serialize($res));
	}

	public function sendReply($pkt, $peer = NULL) {
		$this->sendMsg(pack('n', strlen($pkt)) . $pkt);
	}

	protected function parseBuffer() {
		while($this->ok) {
			if (strlen($this->buf) < 2) break;
			$len = unpack('n', $this->buf);
			$len = $len[1];
			if (strlen($this->buf) < (2+$len)) break;

			$dat = substr($this->buf, 2, $len);
			$this->buf = substr($this->buf, $len+2);
			$this->receivePacket($dat);
		}
	}
}

