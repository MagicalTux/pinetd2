<?php
/*   Portable INET daemon v2 in PHP
 *   Copyright (C) 2007 Mark Karpeles <mark@kinoko.fr>
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, write to the Free Software
 *   Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


namespace pinetd;

use \SimpleXMLElement;
use \DOMDocument;

/**
 * \brief This class handles config-related work
 */
class ConfigManager {
	static private $self = null;
	private $config = 'config.xml';
	private $xml = null;

	public function invoke() {
		if (is_null(self::$self)) {
			self::$self = new self;
		}
		return self::$self;
	}

	public function __get($var) {
		return $this->xml->$var;
	}

	protected function __construct() {
		if (!is_readable(PINETD_ROOT.'/'.$this->config)) {
			if (file_exists(PINETD_ROOT.'/config.php')) {
				$this->parseOldConfig(PINETD_ROOT.'/config.php');
			}
			if (!file_exists(PINETD_ROOT.'/config.php')) {
				$this->xml = new SimpleXMLElement(file_get_contents(PINETD_ROOT . '/etc/default_config.xml'));
				$this->saveConfig();
			}
		} else {
			$this->xml = new SimpleXMLElement(PINETD_ROOT.'/'.$this->config, LIBXML_NOBLANKS | LIBXML_NSCLEAN, true);
		}
		if (isset($this->xml->Global->RemoveMe)) {
			echo rtrim($this->xml->Global->RemoveMe)."\n";
			exit(8);
		}
		$this->saveConfig();
	}

	public function setConfigVar($var, $val) {
		if(is_null($this->xml)) $this->xml = new SimpleXMLElement(file_get_contents(PINETD_ROOT . '/etc/default_config.xml'));
		$cur = $this->xml;
		$var = str_replace(':', '/:', $var);
		while(($pos=strpos($var, '/')) !== false) {
			$sub = substr($var, 0, $pos);
			$var = substr($var, $pos + 1);
			if ($sub == '') continue;
			if (!isset($cur->$sub)) {
				$cur = $cur->addChild($sub);
			} else {
				$cur = $cur->$sub;
			}
		}
		if (substr($var, 0, 1) == ':') {
			$var = substr($var, 1);
			if (!isset($cur[$var])) {
				$cur->addAttribute($var, $val);
			} else {
				$cur[$var] = $val;
			}
		} else {
			if (!isset($cur->$var)) {
				$cur->addChild($var, $val);
			} else {
				$cur->$var = $val;
			}
		}
//		echo $var.' => '.$val."\n";
	}

	public function saveConfig() {
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->formatOutput = true;
		$domnode = dom_import_simplexml($this->xml);
		$domnode = $doc->importNode($domnode, true);
		$domnode = $doc->appendChild($domnode);
		$fp =@fopen(PINETD_ROOT.'/'.$this->config.'~', 'w');
		if (!$fp) {
			return false;
		}
		fwrite($fp, $doc->saveXML());
		fclose($fp);
		rename(PINETD_ROOT.'/'.$this->config.'~', PINETD_ROOT.'/'.$this->config);
		return true;
	}

	public function read($var) {
		$elem = $this->xml->xpath($var);
		if (count($elem) != 1) throw new Exception('No such - or too many - of this path : '.$var);
		$elem = $elem[0];
		return $elem[0];
	}

	private function parseOldConfig($file) {
		$old_vars = array(
			'remove_me' => 'Global/RemoveMe',
			'servername' => 'Global/Name',
			'pidfile' => 'Global/PidFile',
			'bind_ip' => 'Global/Network/Bind/Ip',
			'pasv_ip' => 'Global/Network/Bind/Ip:External',
			'sql_user' => 'Global/Storage/MySQL:Login',
			'sql_pass' => 'Global/Storage/MySQL:Password',
			'sql_host' => 'Global/Storage/MySQL:Host',
			'max_users' => 'Daemons/FTPd/MaxUsers',
			'max_anonymous' => 'Daemons/FTPd/MaxUsers:Anonymous',
			'ftp_server' => 'Daemons/FTPd/Name',
			'max_users_per_ip' => 'Daemons/FTPd/Network:MaxUsersPerIp',
			'ftp_owner_u' => 'Daemons/FTPd/SUID:User',
			'ftp_owner_g' => 'Daemons/FTPd/SUID:Group',
			'PHPMAILD_STORAGE' => 'Daemons/PMaild/Mails:Path',
			'PHPMAILD_DEFAULT_DOMAIN' => 'Daemons/PMaild/DefaultDomain',
			'PHPMAILD_DB_NAME' => 'Daemons/PMaild/Storage/MySQL:Database',
			'pmaild_mta_max_processes' => 'Daemons/PMaild/MTA:MaxProcesses',
			'pmaild_mta_thread_start_threshold' => 'Daemons/PMaild/MTA:StartThreshold',
			'pmaild_mta_max_attempt' => 'Daemons/PMaild/MTA:MaxAttempt',
			'pmaild_mta_mail_max_lifetime' => 'Daemons/PMaild/MTA:MailMaxLifetime',
		);
		if(!extension_loaded('tokenizer')) {
			die("Can't parse old config without tokenizer!\n");
		}
		$dat = token_get_all(file_get_contents($file));
		$cmd = array();
		$config = array();
		foreach($dat as $tok) {
			if (is_string($tok)) {
				if ($tok == ';') {
					switch($cmd['op']) {
						case 'ignore':
							break;
						case 'eq';
							$config[$cmd['var']] = $cmd['value'];
							break;
					}
					$cmd = array();
					continue;
				}
				switch($tok) {
					case '=':
						$cmd['op'] = 'eq';
						break;
					case ',':
						if ($cmd['definemode'] == 1) {
							$cmd['definemode']=2;
							break;
						}
					case '(':
					case '.':
					case ')':
						break;
					default:
						var_dump($tok);
						exit;
				}
				continue;
			}
			if ($cmd['op'] == 'ignore') {
				continue;
			}
			switch($tok[0]) {
				case T_OPEN_TAG:
					break; // do not care about this
				case T_EXIT:
					$cmd['var'] = 'remove_me';
					$cmd['op'] = 'eq';
					break;
				case T_ARRAY:
					$cmd['arraymode'] = 1;
					$cmd['op'] = 'ignore';
					break;
				case T_VARIABLE:
					$cmd['var'] = substr($tok[1], 1);
					break;
				case T_CONSTANT_ENCAPSED_STRING:
				case T_LNUMBER;
					$val = eval('return '.$tok[1].';');
					if ($cmd['definemode'] == 1) {
						$cmd['var'].=$val;
						break;
					}
					$cmd['value'] .= $val;
					break;
				case T_COMMENT:
				case T_WHITESPACE:
					continue;
				case T_STRING:
					if ($tok[1] == 'define') {
						$cmd['definemode'] = 1;
						$cmd['op'] = 'eq';
						break;
					}
				default:
					$tok[0] = token_name((int)$tok[0]);
					var_dump($tok);
					exit;
			}
		}
		// need to map param from old config to new stuff
		foreach($config as $var => $val) {
			if (!isset($old_vars[$var])) {
				echo 'CONVERT_CONFIG: Dropping unknown variable '.$var."\n";
				continue;
			}
			$this->setConfigVar($old_vars[$var], $val);
		}
		return $this->saveConfig();
	}
}


