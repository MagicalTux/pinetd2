<?php

namespace Daemon\DNSd;

use pinetd\SQL;

class DbEngine {
	protected $localConfig;
	protected $tcp;

	function __construct($localConfig, $tcp) {
		$this->localConfig = $localConfig;

		// check table struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);

		$storage = relativeclass($this, 'Storage');
		$storage::validateTables($this->sql);

		$this->tcp = $tcp;
	}

	public function createZone($zone) {
		// Try to insert zone
		$zone = strtolower($zone);
		$now = $this->sql->now();

		$data = array(
			'zone' => $zone,
			'created' => $now,
			'changed' => $now,
		);

		if (!$this->sql->insert('zones', $data)) return false;

		$id = $this->sql->insert_id;
		$this->tcp->dispatch('zones', $id, $data);

		return $id;
	}

	public function getZone($zone) {
		$res = $this->sql->query('SELECT `zone_id` FROM `zones` WHERE `zone` = '.$this->sql->quote_escape($zone))->fetch_assoc();

		return $res['zone_id'];
	}

	public function addRecord($zone, $host, $type, $value, $ttl = 86400) {
		if (!is_array($value)) {
			$value = array('data' => $value);
		}

		$insert = $value;
		$insert['zone'] = strtolower($zone);
		$insert['host'] = strtolower($host);
		$insert['type'] = strtoupper($type);
		$insert['ttl'] = $ttl;
		$insert['changed'] = $this->sql->now();

		$fields = '';
		$values = '';
		foreach($insert as $var => $val) {
			$fields .= ($fields == ''?'':', ') . '`' . $var . '`';
			$values .= ($values == ''?'':', ') . $this->sql->quote_escape($val);
		}

		$req = 'INSERT INTO `zone_records` ('.$fields.') VALUES ('.$values.')';

		$res = $this->sql->query($req);
		if (!$res) return false;

		return $this->sql->insert_id;
	}

	public function dumpZone($zone, $start = 0, $limit = 50) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}

		$req = 'SELECT * FROM `zone_records` WHERE `zone` = '.$this->sql->quote_escape($zone).' LIMIT '.((int)$start).','.((int)$limit);
		$res = $this->sql->query($req);

		$final_res = array();

		while($row = $res->fetch_assoc()) {
			foreach($row as $var=>$val) if (is_null($val)) unset($row[$var]);
			$final_res[] = $row;
		}

		return $final_res;
	}

	public function createDomain($domain, $zone) {
		// Try to insert domain
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		$domain = strtolower($domain);
		$res = $this->sql->query('INSERT INTO `domains` (`domain`, `zone`, `created`, `changed`) VALUES ('.$this->sql->quote_escape($domain).', '.$this->sql->quote_escape($zone).', NOW(), NOW())');
		if (!$res) return false;

		return $this->sql->insert_id;
	}
}

