<?php

namespace Daemon\DNSd;

class Storage {
	static private $global_tables = array(
		'domains' => array(
			'key' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'domain' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'UNIQUE:piece_lookup',
			),
			'zone' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'key' => 'zone',
			),
			'created' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
			'changed' => array(
				'type' => 'DATETIME',
				'null' => false,
				'key' => 'changed',
			),
		),

		'zones' => array(
			'zone_id' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'zone' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'UNIQUE:zone',
			),
			'created' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
			'changed' => array(
				'type' => 'DATETIME',
				'null' => false,
				'key' => 'changed',
			),
		),
		'zone_records' => array(
			'record_id' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'zone' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'key' => 'domain_lookup',
			),
			'host' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'domain_lookup',
			),
			'ttl' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
			),
			'type' => array(
				'type' => 'VARCHAR',
				'size' => 10,
				'null' => false,
				'key' => 'domain_lookup',
			),
			'mx_priority' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => true,
			),
			'data' => array(
				'type' => 'TEXT',
				'null' => false,
			),
			'resp_person' => array(
				'type' => 'TEXT',
				'null' => true,
			),
			'serial' => array(
				'type' => 'INT',
				'unsigned' => true,
				'null' => true,
			),
			'refresh' => array(
				'type' => 'INT',
				'unsigned' => true,
				'null' => true,
			),
			'retry' => array(
				'type' => 'INT',
				'unsigned' => true,
				'null' => true,
			),
			'expire' => array(
				'type' => 'INT',
				'unsigned' => true,
				'null' => true,
			),
			'minimum' => array(
				'type' => 'INT',
				'unsigned' => true,
				'null' => true,
			),
			'changed' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
		),
		'deletions' => array(
			'key' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'deletion_table' => array(
				'type' => 'ENUM',
				'values' => array('zones', 'domains', 'zone_records'),
			),
			'deletion_id' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
			),
			'changed' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
		),
		'status' => array(
			'status_id' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'updated' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
			'oldest_record' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
		),
	);

	public static function validateTables($SQL) {
		foreach(self::$global_tables as $name => $struct) {
			$SQL->validateStruct($name, $struct);
		}
	}
}

