<?php
namespace Daemon\PMaild\MTA;

class Storage {
	static private $global_tables = array(
		'dnsbl_cache' => array(
			'ip' => array(
				'type' => 'VARCHAR',
				'size' => 15,
				'null' => false,
				'key' => 'PRIMARY',
			),
			'list' => array(
				'type' => 'VARCHAR',
				'size' => 30,
				'null' => false,
				'key' => 'PRIMARY',
			),
			'regdate' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
			'clear' => array(
				'type' => 'ENUM',
				'values' => array('Y', 'N'),
				'default' => 'Y',
				'null' => false,
			),
			'answer' => array(
				'type' => 'VARCHAR',
				'size' => 15,
				'null' => false,
			),
		),
		'domainaliases' => array(
			'domain' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'PRIMARY',
			),
			'domainid' => array(
				'type' => 'INT',
				'size' => 10,
				'null' => false,
				'unsigned' => true,
			),
			'last_recv' => array(
				'type' => 'DATETIME',
				'null' => true,
				'default' => null,
			),
		),
		'domains' => array(
			'domainid' => array(
				'type' => 'INT',
				'size' => 10,
				'null' => false,
				'unsigned' => true,
				'auto_increment' => true,
				'key' => 'PRIMARY',
			),
			'domain' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'default' => '',
				'key' => 'UNIQUE:domain',
			),
			'flags' => array(
				'type'=>'SET',
				'values'=>array(
					'create_account_on_mail',
					'fake_domain',
					'drop_email_on_spam',
					'account_without_plus_symbol',
				),
				'default'=>'',
				'null'=>false,
			),
			'antispam' => array(
				'type'=>'SET',
				'values'=>array('resend','rbl','internal','spamassassin'),
				'default'=>'',
				'null'=>false,
			),
			'dnsbl' => array(
				'type'=>'SET',
				'values'=>array('spews1', 'spews2', 'spamcop', 'spamhaus'),
				'default'=>'',
				'null'=>false,
			),
			'antivirus' => array(
				'type' => 'SET',
				'values' => array('clam'),
				'default' => '',
				'null' => false,
			),
			'protocol' => array(
				'type' => 'SET',
				'values' => array('smtp', 'pop3', 'imap4'),
				'default' => 'pop3,imap4',
				'null' => false,
			),
			'created' => array(
				'type'=>'DATETIME',
				'null'=>false,
				'default'=>'0000-00-00 00:00:00',
			),
			'last_recv' => array(
				'type' => 'DATETIME',
				'null' => true,
				'default'=>NULL,
			),
		),
		'hosts' => array(
			'ip' => array(
				'type' => 'VARCHAR',
				'size' => 15,
				'null' => false,
				'default' => '',
				'key' => 'PRIMARY',
			),
			'type' => array(
				'type' => 'ENUM',
				'values' => array('trust', 'spam'),
				'null' => false,
				'default' => 'trust',
			),
			'regdate' => array(
				'type' => 'DATETIME',
				'null' => false,
				'default' => '0000-00-00 00:00:00',
			),
			'expires' => array(
				'type' => 'DATETIME',
				'null' => true,
				'default' => null,
			),
			'spampoints' => array(
				'type' => 'INT',
				'size' => 11,
				'null' => false,
				'default' => 0,
			),
			'spamupdate' => array(
				'type' => 'DATETIME',
				'null' => true,
				'default' => null,
			),
		),
		'mailqueue' => array(
			'mlid' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'key' => 'PRIMARY',
			),
			'to' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
				'default' => '',
				'key' => 'PRIMARY',
			),
			'from' => array(
				'type' => 'VARCHAR',
				'size' => 255,
				'null' => true,
				'default' => null,
			),
			'queued' => array(
				'type' => 'DATETIME',
				'null' => false,
			),
			'tracker' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => true,
			),
			'pid' => array(
				'type' => 'INT',
				'size' => 10,
				'unsigned' => true,
				'null' => true,
				'default' => null,
			),
			'attempt_count' => array(
				'type' => 'INT',
				'unsigned' => true,
				'size' => 10,
				'null' => false,
				'default' => 0,
			),
			'last_attempt' => array(
				'type' => 'DATETIME',
				'default' => null,
				'null' => true,
			),
			'last_error' => array(
				'type' => 'TEXT',
				'default' => null,
				'null' => true,
			),
			'next_attempt' => array(
				'type' => 'DATETIME',
				'default' => null,
				'null' => true,
			),
		),
	);

	static private $tables_struct = array(
		'z%s_accounts' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'user' => array(
				'type'=>'VARCHAR',
				'size'=>64,
				'null'=>false,
				'default'=>'',
				'key'=>'UNIQUE:user',
			),
			'password' => array(
				'type'=>'VARCHAR',
				'size'=>40,
				'null'=>true,
				'default'=>'',
			),
			'last_login' => array(
				'type'=>'DATETIME',
				'null'=>true,
				'default'=>NULL,
			),
			'mail_count' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>true,
				'unsigned'=>true,
			),
			'mail_quota' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>true,
				'unsigned'=>true,
			),
			'redirect'=>array(
				'type'=>'VARCHAR',
				'size'=>255,
				'null'=>true,
				'default'=>NULL,
			),
		),
		'z%s_alias' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'user' => array(
				'type'=>'VARCHAR',
				'size'=>64,
				'null'=>false,
				'default'=>'',
				'key'=>'UNIQUE:user',
			),
			'last_transit' => array(
				'type'=>'DATETIME',
				'null'=>true,
				'default'=>NULL,
			),
			'real_target' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
			),
			'mail_target' => array(
				'type'=>'VARCHAR',
				'size'=>255,
				'null'=>true,
				'default'=>NULL,
			),
			'http_target' => array(
				'type'=>'VARCHAR',
				'size'=>255,
				'null'=>true,
				'default'=>NULL,
			),
		),
		'z%s_folders' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'account' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>false,
				'key'=>'UNIQUE:folder',
			),
			'name' => array(
				'type'=>'VARCHAR',
				'size'=>32,
				'null'=>false,
				'key'=>'UNIQUE:folder',
			),
			'parent'=>array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>true,
				'default'=>null,
				'key'=>'UNIQUE:folder',
			),
			'flags' => array(
				'type' => 'SET',
				'values'=>array('noselect'),
				'default'=>'',
				'null'=>false,
			),
			'subscribed' => array(
				'type' => 'INT',
				'size' => 10,
				'unsigned' => true,
				'null' => false,
				'default' => 0,
			),
		),
		'z%s_mails' => array(
			'mailid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'folder'=> array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'key'=>'folder',
			),
			'userid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'key'=>'UNIQUE:userid',
			),
			'size' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
			),
			'uniqname' => array(
				'type'=>'VARCHAR',
				'size'=>128,
				'null'=>false,
				'key'=>'UNIQUE:userid',
			),
			'flags' => array(
				'type'=>'SET',
				'values'=>array('seen','answered','flagged','deleted','draft','recent'),
				'default'=>'recent',
				'null'=>false,
			),
		),
		'z%s_mailheaders' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>false,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'userid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>false,
				'key'=>'usermail',
			),
			'mailid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>false,
				'key'=>'usermail',
			),
			'header' => array(
				'type'=>'VARCHAR',
				'size'=>64,
				'null'=>false,
				'key'=>'header',
			),
			'content' => array(
				'type'=>'TEXT',
				'null'=>false,
				'key'=>'FULLTEXT:content',
			),
		),
		'z%s_filter' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'userid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'unsigned'=>true,
				'null'=>false,
				'key'=>'usermail',
			),
			'name' => array(
				'type' => 'VARCHAR',
				'size' => 128,
				'null' => false,
			),
		),
		'z%s_filter_cond' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'filterid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'key' => 'filterid',
			),
			'priority' => array(
				'type' => 'INT',
				'size' => 10,
				'default' => 0,
			),
			'source' => array(
				'type' => 'ENUM',
				'values' => array('header'),
				'default' => 'header',
			),
			'type' => array(
				'type' => 'ENUM',
				'values' => array('exact', 'contains', 'preg'),
				'default' => 'contains',
			),
			'arg1' => array(
				'type' => 'VARCHAR',
				'size' => 255,
			),
			'arg2' => array(
				'type' => 'VARCHAR',
				'size' => 255,
			),
		),
		'z%s_filter_act' => array(
			'id' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'auto_increment'=>true,
				'key'=>'PRIMARY',
			),
			'filterid' => array(
				'type'=>'BIGINT',
				'size'=>20,
				'null'=>false,
				'unsigned'=>true,
				'key' => 'filterid',
			),
			'action' => array(
				'type' => 'ENUM',
				'values' => array('move', 'copy', 'drop', 'flags'),
				'default' => 'move',
			),
			'arg1' => array(
				'type' => 'VARCHAR',
				'size' => 255,
			),
		),
	);

	function validateTables($SQL, $id = null) {
		$t = &self::$global_tables;
		if (!is_null($id)) {
			$id = (int)$id; // force cast to int
			$option = str_pad($id, 10, '0', STR_PAD_LEFT);

			foreach(self::$tables_struct as $name => $struct) {
				$old_name = sprintf($name, $option);
				$name = sprintf($name, $id);
				// check for legacy table
				if ($SQL->query('SELECT 1 FROM `'.$old_name.'` LIMIT 1')) {
					$SQL->query('DROP TABLE `'.$name.'`');
					$SQL->query('RENAME TABLE `'.$old_name.'` TO `'.$name.'`');
				}

				$SQL->validateStruct($name, $struct);
			}
			return; // o rly?
		}
		foreach(self::$global_tables as $name => $struct) {
			$SQL->validateStruct($name, $struct);
		}
	}
}


