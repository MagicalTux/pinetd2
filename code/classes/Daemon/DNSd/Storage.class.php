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
			'parent_key' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => true,
				'key' => 'piece_lookup',
			),
			'domain' => array(
				'type' => 'VARCHAR',
				'size' => 63,
				'null' => false,
				'key' => 'piece_lookup',
			),
			'zone' => array(
				'type' => 'VARCHAR',
				'size' => 128,
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
				'key' => 'PRIMARY',
			),
			'zone' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'domain_lookup',
			),
			'prefix' => array(
				'type' => 'VARCHAR',
				// ...
			),
		),
	);

	public static function validateTables($SQL) {
		foreach(self::$global_tables as $name => $struct) {
			$SQL->validateStruct($name, $struct);
		}
	}
}

