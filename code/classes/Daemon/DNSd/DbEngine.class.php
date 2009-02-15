<?php

namespace Daemon\DNSd;

use pinetd\Logger;
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

	protected function tableKey($table) {
		switch($table) {
			case 'domains': return 'key';
			case 'zone_records': return 'record_id';
			case 'zones': return 'zone_id';
			case 'deletions': return 'deletion_id';
			default: return NULL;
		}
	}

	public function processUpdate($table, $data) {
		$key = $this->tableKey($table);
		if (is_null($key)) {
			Logger::log(Logger::LOG_WARN, 'Got update from DNSd master for unknown table '.$table);
			return;
		}
		$key_val = $data[$key];

		if ($this->sql->query('SELECT 1 FROM `'.$table.'` WHERE `'.$key.'` = '.$this->sql->quote_escape($key_val))->fetch_assoc()) {
			// update
			unset($data[$key]);
			$req = '';
			foreach($data as $var => $val) $req.=($req==''?'':',').'`'.$var.'` = '.$this->sql->quote_escape($val);
			$req = 'UPDATE `'.$table.'` SET '.$req.' WHERE `'.$key.'` = '.$this->sql->quote_escape($key_val);
			$this->sql->query($req);
		} else {
			$this->sql->insert($table, $data);
		}

		if ($table == 'deletions') {
			$delete_key = $this->tableKey($data['deletion_table']);
			if (!is_null($delete_key)) {
				$req = 'DELETE FROM `'.$data['deletion_table'].'` WHERE `'.$delete_key.'` = '.$this->sql->quote_escape($data['deletion_id']);
				$this->sql->query($req);
			}
		}
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
		$data['zone_id'] = $id;
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

		$res = $this->sql->insert('zone_records', $insert);
		if (!$res) return false;

		$id = $this->sql->insert_id;
		$insert['record_id'] = $id;

		$this->tcp->dispatch('zone_records', $id, $insert);

		return $id;
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
		$insert = array(
			'domain' => $domain,
			'zone' => $zone,
			'created' => $this->sql->now(),
			'changed' => $this->sql->now(),
		);

		$res = $this->sql->insert('domains', $insert);
		if (!$res) return false;

		$id = $this->sql->insert_id;
		$insert['key'] = $id;

		$this->tcp->dispatch('domains', $id, $insert);
		return $id;
	}

	public function lastUpdateDate() {
		$recent = 0;

		foreach(array('deletions', 'domains', 'zone_records', 'zones') as $table) {
			$req = 'SELECT UNIX_TIMESTAMP(MAX(`changed`)) AS changed FROM `'.$table.'`';
			$res = $this->sql->query($req)->fetch_assoc();
			if ($res) $recent = max($recent, $res['changed']);
		}

		return $recent;
	}
}

