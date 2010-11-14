<?php

namespace Daemon\DNSd;

use pinetd\Logger;
use pinetd\Timer;
use pinetd\SQL;

class DbEngine {
	protected $localConfig;
	protected $tcp;
	protected $parent;
	protected $domainHitCache = array();
	protected $heartbeat = array();

	function __construct($parent, $localConfig, $IPC) {
		$this->localConfig = $localConfig;

		// check table struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);

		$storage = relativeclass($this, 'Storage');
		$storage::validateTables($this->sql);

		$tcp = $IPC->openPort('DNSd::TCPMaster::'.$this->sql->unique());

		$this->tcp = $tcp;
		$this->parent = $parent;

		Timer::addTimer(array($this, 'processHits'), 30, $extra = null, true);
		Timer::addTimer(array($this, 'cleanHeartbeat'), 60, $extra = null, true);
	}

	function __call($func, $args) {
		return NULL;
	}

	function unique() {
		return $this->sql->unique();
	}

	public function processHits() {
		if (!$this->domainHitCache) return true;

		if (isset($this->localConfig['Master'])) {
			// we are slave
			foreach($this->domainHitCache as $domain => $hits) {
				$this->parent->domainHit($domain, $hits);
			}
			$this->domainHitCache = array();
		}

		return true;
	}

	public function getDomainHits() {
		$res = $this->domainHitCache;
		$this->domainHitCache = array();
		return $res;
	}

	protected function tableKey($table) {
		switch($table) {
			case 'domains': return 'key';
			case 'zone_records': return 'record_id';
			case 'zones': return 'zone_id';
			case 'deletions': return 'key';
			case 'heartbeat': return 'heartbeat_id';
			default: return NULL;
		}
	}

	protected function doDelete($table, $key, $value) {
		$this->sql->query('BEGIN TRANSACTION');

		// delete entry
		if (!$this->sql->query('DELETE FROM `'.$table.'` WHERE `'.$key.'` = '.$this->sql->quote_escape($value))) {
			$this->sql->query('ROLLBACK');
			return false;
		}

		if ($this->sql->affected_rows < 1) {
			$this->sql->query('ROLLBACK');
			return false;
		}
		
		// store delete event and dispatch it
		$insert = array(
			'deletion_table' => $table,
			'deletion_id' => $value,
			'changed' => $this->sql->now(),
		);
		if (!$this->sql->insert('deletions', $insert)) {
			$this->sql->query('ROLLBACK');
			return false;
		}

		$insert['key'] = $this->sql->insert_id;

		$this->sql->query('COMMIT');

		$this->tcp->dispatch('deletions', $insert['key'], $insert);

		return true;
	}

	public function processUpdate($table, $data) {
		if (!isset($this->localConfig['Master'])) return NULL; // I GOT NO MASTERS!

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

	public function domainHit($domain, $hit_count = 1) {
		$this->domainHitCache[strtolower($domain)] += $hit_count;
	}

	/*****
	 ** Zones management
	 *****/

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

	public function deleteZone($zone) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		if (!$zone) return false;

		return $this->doDelete('zones', 'zone_id', $zone);
	}

	/*****
	 ** Records management
	 *****/

	public function addRecord($zone, $host, $type, $value, $ttl = 86400) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}
		if (!$zone) return NULL;

		if (!is_array($value)) {
			$value = array('data' => $value);
		}

		$allowed = array('mx_priority', 'data', 'resp_person', 'serial', 'refresh', 'retry', 'expire', 'minimum', 'changed');

		$insert = array();

		foreach($allowed as $var) {
			if (array_key_exists($var, $value)) $insert[$var] = $value[$var];
		}

		$insert['zone'] = $zone;
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

	public function changeRecord($record, $host, $type, $value, $ttl = NULL) {

		// load this record
		$found = $this->sql->query('SELECT 1 FROM `zone_records` WHERE `record_id` = '.$this->sql->quote_escape($record))->fetch_assoc();
		if (!$found) return false;

		$data = array();

		if (is_null($value)) {
			$value = array();
		} elseif (!is_array($value)) {
			$value = array('data' => $value);
		}

		$allowed = array('mx_priority', 'data', 'resp_person', 'serial', 'refresh', 'retry', 'expire', 'minimum', 'changed');

		foreach($allowed as $var) {
			if (isset($value[$var])) $data[$var] = $value[$var];
		}

		if (!is_null($host)) $data['host'] = strtolower($host);
		if (!is_null($type)) $data['type'] = strtoupper($type);
		if (!is_null($ttl)) $data['ttl'] = $ttl;
		$data['changed'] = $this->sql->now();

		$req = '';

		foreach($data as $var => $val) {
			$req .= ($req == ''?'':', ') . '`' . $var . '` = ' . $this->sql->quote_escape($val);
		}

		$req = 'UPDATE `zone_records` SET '.$req.' WHERE `record_id` = '.$this->sql->quote_escape($record);

		$data['record_id'] = $record;

		$this->tcp->dispatch('zone_records', $record, $data);

		return (bool)$this->sql->query($req);
	}

	public function deleteRecord($rid) {
		if (!$rid) return false;

		return $this->doDelete('zone_records', 'record_id', $rid);
	}

	public function dumpZone($zone, $start = 0, $limit = 500) {
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

	/*****
	 ** Domains management
	 *****/

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

	// connect a domain to another zone
	public function changeDomain($domain, $zone) {
		if (!is_numeric($zone)) {
			$zone = $this->getZone($zone);
		}

		if (!is_numeric($domain)) {
			$domain = $this->getDomain($domain);
		}

		// load this domain
		$found = $this->sql->query('SELECT * FROM `domains` WHERE `key` = '.$this->sql->quote_escape($domain))->fetch_assoc();
		if (!$found) return false;

		$data = $found;
		unset($data['key']);

		$data['changed'] = $this->sql->now();
		$data['zone'] = $zone;
		$data['pzc_zone'] = NULL;
		$data['pzc_stamp'] = NULL;

		$req = '';

		foreach($data as $var => $val) {
			$req .= ($req == ''?'':', ') . '`' . $var . '` = ' . $this->sql->quote_escape($val);
		}

		$req = 'UPDATE `domains` SET '.$req.' WHERE `key` = '.$this->sql->quote_escape($domain);

		$data['key'] = $domain;

		if (!$this->sql->query($req)) return false;

		$this->tcp->dispatch('domains', $domain, $data);

		return true;
	}

	public function domainPzc($domain, $pzc_zone, $pzc_stamp) {
		if (!is_numeric($pzc_zone)) {
			$pzc_zone = $this->getZone($pzc_zone);
		}
		if (!is_numeric($domain)) {
			$domain = $this->getDomain($domain);
		}

		if ($pzc_stamp < time()) {
			return $this->changeDomain($domain, $pzc_zone);
		}

		// load this domain
		$found = $this->sql->query('SELECT * FROM `domains` WHERE `key` = '.$this->sql->quote_escape($domain))->fetch_assoc();
		if (!$found) return false;
		
		$data = $found;
		unset($data['key']);

		$data['changed'] = $this->sql->now();
		$data['pzc_zone'] = $pzc_zone;
		$data['pzc_stamp'] = $pzc_stamp;

		$req = '';
		foreach($data as $var => $val) {
			$req .= ($req == ''?'':', ') . '`' . $var . '` = ' . $this->sql->quote_escape($val);
		}

		$req = 'UPDATE `domains` SET '.$req.' WHERE `key` = '.$this->sql->quote_escape($domain);

		$data['key'] = $domain;

		if (!$this->sql->query($req)) return false;

		$this->tcp->dispatch('domains', $domain, $data);

		return true;
	}

	public function getDomain($domain) {
		$res = $this->sql->query('SELECT `key` FROM `domains` WHERE `domain` = '.$this->sql->quote_escape($domain))->fetch_assoc();

		return $res['key'];
	}

	public function deleteDomain($domain) {
		if (!is_numeric($domain)) {
			$domain = $this->getDomain($domain);
		}
		if (!$domain) return false;

		return $this->doDelete('domains', 'key', $domain);
	}

	/*****
	 ** Heartbeat Management
	 *****/
	
	public function handleHeartbeat($hb, $peer_data) {
		if (!is_array($peer_data)) $peer_data = explode(':', $peer_data);
		$peer_key = $peer_data[0].'_'.$peer_data[1].'_'.$hb['pid'];
		$k = $hb['heartbeat'];
		$added = false;

		if (!isset($this->heartbeat[$k])) {
			// load from database
			$res = $this->sql->query('SELECT * FROM `heartbeat` WHERE `heartbeat_id` = '.$this->sql->quote_escape($k))->fetch_assoc();
			if (!$res) return 3; /* PKT_REPLY_BADPASS */
			$res['expires'] = microtime(true) + 60;
			$res['up'] = false;
			$res['peer_key'] = '?';
			$res['updated'] = 0;
			$this->heartbeat[$k] = $res;
		}

		$i = &$this->heartbeat[$k];
		// check hash
		$hash = sha1(pack('H*', $hb['data']) . $i['key'], true);
		if ($hash != pack('H*', $hb['hash'])) return 3; /* PKT_REPLY_BADPASS */
		// check stamp
		if (abs($hb['stamp'] - microtime(true)) > 5) return 2; /* PKT_REPLY_SYNC */
		// check & update peer_key
		if (($i['peer_key'] != $peer_key) && ($i['updated'] > (microtime(true)-15))) return 4; /* PKT_REPLY_DUPE */
		if ($i['peer_key'] != $peer_key) {
			$i['peer_key'] = $peer_key;
			$added = true;
		}
		
		$i['loadavg'] = $hb['loadavg1']; // use 1 minute load average
		$i['up'] = $i['loadavg'] < $i['max_loadavg'];
		$i['expires'] = microtime(true) + 22;
		$i['updated'] = microtime(true);

		$this->IPC->broadcast('DNSd::heartbeat::'.$this->sql->unique(), $i);

		if ($added) {
			return 1; /* PKT_REPLY_ADDED */
		} else {
			return 0;
		}
	}

	public function cleanHeartbeat() {
		foreach($this->heartbeat as $key => $data) {
			if ($data['expires'] < microtime(true)) {
				unset($this->heartbeat[$key]);
				$this->IPC->broadcast('DNSd::heartbeat::'.$this->sql->unique(), array('heartbeat_id' => $key, 'clear' => true));
			}
		}
	}

	/*****
	 ** Etc
	 *****/

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

