<?php

namespace Daemon\DNSd;

use Daemon\DNSd\Type\RFC1035;
use pinetd\SQL;

class Engine {
	const DNS_CLASS_IN = 1; // Teh Internet
	const DNS_CLASS_CS = 2; // CSNET class (obsolete, used only for examples in some obsolete RFCs)
	const DNS_CLASS_CH = 3; // CHAOS
	const DNS_CLASS_HS = 4; // Hesiod [Dyer 87]

	protected $parent;
	protected $IPC;
	protected $packet_class;
	protected $localConfig;
	protected $sql;
	protected $sql_stmts = NULL;
	protected $sql_stmts_tmp = array();

	public function __construct($parent, $IPC, $localConfig) {
		$this->parent = $parent;
		$this->IPC = $IPC;
		$this->packet_class = relativeclass($this, 'Packet');

		$this->localConfig = $localConfig;
		// connect to SQL
		$this->sql = SQL::Factory($this->localConfig['Storage']);

		// check if tables exists
		// TODO: make this look better
		while(!$this->sql->query('SELECT 1 FROM `status` LIMIT 1')) usleep(500000);
	}

	protected function prepareStatements() {
		$stmts = array(
			'get_domain' => 'SELECT `zone` FROM `domains` WHERE `domain` = ?',
			'get_record_any' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = ?',
			'get_record' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = ? AND `type` IN (?, \'CNAME\')',
			'get_authority' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = \'\' AND `type` IN (\'NS\')',
		);

		foreach($stmts as $name => $query) {
			if (isset($this->sql_stmts_tmp[$name])) continue;

			$stmt = $this->sql->prepare($query);
			if (!$stmt) return false;

			$this->sql_stmts_tmp[$name] = $stmt;
		}

		$this->sql_stmts = $this->sql_stmts_tmp;

		return true;
	}

	protected function handleQuestion($pkt, $question) {
		switch($question['qclass']) {
			case self::DNS_CLASS_CH:
				$handler = 'handleChaosQuestion';
				break;
			case self::DNS_CLASS_IN:
			default:
				$handler = 'handleInternetQuestion';
				break;
		}
		return $this->$handler($pkt, $question['qname'], $question['qtype']);
	}

	protected function handleInternetQuestion($pkt, $name, $type, $subquery = false) {
		// strip ending "."
		if (substr($name, -1) == '.') $name = substr($name, 0, -1);

		// check sql statements
		if (is_null($this->sql_stmts)) {
			if (!$this->prepareStatements()) return;
		}

		$typestr = Type::typeToString($type);
		if (is_null($typestr)) return NULL;

		// search this domain
		$domain = $name;
		$host = '';
		while(1) {
			$res = $this->sql_stmts['get_domain']->run(array($domain))->fetch_assoc();
			if (!$res) {
				$pos = strpos($domain, '.');
				if ($pos === false) return; // TODO: send SERVFAIL not auth
				$host .= ($host==''?'':'.').substr($domain, 0, $pos);
				$domain = substr($domain, $pos + 1);
				continue;
			}

			break;
		}

		$zone = $res['zone'];
		$ohost = $host;
		if ($ohost != '') $ohost .= '.';

		$pkt->setDefaultDomain($domain);

		while(1) {
			// got host & domain, lookup...
			if ($type != Type\RFC1035::TYPE_ANY) {
				$res = $this->sql_stmts['get_record']->run(array($zone, $host, $typestr));
			} else {
				$res = $this->sql_stmts['get_record_any']->run(array($zone, $host));
			}

			$found = 0;
			$add_lookup = array();
			while($row = $res->fetch_assoc()) {
				++$found;

				$answer = $this->makeResponse($row, $pkt);
				if (is_null($answer)) continue;

				if ($answer->getType() == Type\RFC1035::TYPE_CNAME) {
					$aname = $row['data'];
					if (substr($aname, -1) != '.') $aname .= '.' . $domain . '.';
					if (strtolower($aname) != $ohost . $domain. '.') {
						$add_lookup[strtolower($aname)] = $aname;
						$pkt->addAnswer($ohost. $domain. '.', $answer, $row['ttl']);
					}
				} else {
					$pkt->addAnswer($ohost. $domain. '.', $answer, $row['ttl']);
				}
			}
			if ($found) break;
			if ($host == '') break;
			if ($host == '*') break; // can't lookup more
			if ($host[0] == '*') $host = (string)substr($host, 2);

			$pos = strpos($host, '.');
			if ($pos === false) {
				$host = '*';
			} else {
				$host = '*' . substr($host, $pos);
			}
		}

		foreach($add_lookup as $aname) {
			$this->handleInternetQuestion($pkt, $aname, $type, true);
		}

		if ($subquery) return;

		// add authority
		$res = $this->sql_stmts['get_authority']->run(array($zone));

		while($row = $res->fetch_assoc()) {
			$answer = $this->makeResponse($row, $pkt);
			if (is_null($answer)) continue;
			$pkt->addAuthority($domain . '.', $answer, $row['ttl']);
		}
	}

	protected function makeResponse($row, $pkt) {
		$atype = Type::stringToType($row['type']);
		if (is_null($atype)) return NULL;

		$answer = Type::factory($pkt, $atype);
		switch($atype) {
			case Type\RFC1035::TYPE_MX:
				$answer->setValue(array('priority' => $row['mx_priority'], 'host' => $row['data']));
				break;
			case Type\RFC1035::TYPE_SOA:
				$answer->setValue(array(
					'mname' => $row['data'],
					'rname' => $row['resp_person'],
					'serial' => $row['serial'],
					'refresh' => $row['refresh'],
					'retry' => $row['retry'],
					'expire' => $row['expire'],
					'minimum' => $row['minimum'],
				));
				break;
			default:
				$answer->setValue($row['data']);
		}
		if ($row['host']) $row['host'].='.';

		return $answer;
	}

	protected function handleChaosQuestion($pkt, $name, $type) {
		if ((strtolower($name) == 'version.dnsd.') && ($type == RFC1035::TYPE_TXT)) {
			$txt = Type::factory($pkt, RFC1035::TYPE_TXT);
			$txt->setValue('DNSd PHP daemon (PHP/'.PHP_VERSION.')');
			$pkt->addAnswer('version.dnsd.', $txt, 0, self::DNS_CLASS_CH);

			$auth = Type::factory($pkt, RFC1035::TYPE_NS);
			$auth->setValue('version.dnsd.');
			$pkt->addAuthority('version.dnsd.', $auth, 0, self::DNS_CLASS_CH);
			return;
		}
	}

	public function handlePacket($data, $peer_info) {
		$pkt = new $this->packet_class();
		if (!$pkt->decode($data)) return;

		$pkt->resetAnswer();

		foreach($pkt->getQuestions() as $question) {
			$this->handleQuestion($pkt, $question);
		}

		$pkt->setFlag('qr', 1);
		$pkt->setFlag('aa', 1);

		$pkt = $pkt->encode();
		//$test = new $this->packet_class();
		//$test->decode($pkt);
		//var_dump($test);

		if (!is_null($pkt)) $this->parent->sendReply($pkt, $peer_info);
	}
}

