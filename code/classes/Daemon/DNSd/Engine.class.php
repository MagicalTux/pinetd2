<?php

namespace Daemon\DNSd;

use Daemon\DNSd\Type\RFC1035;
use pinetd\SQL;
use pinetd\Logger;

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
			'get_zone' => 'SELECT `zone_id` FROM `zones` WHERE `zone` = ?',
			'get_record_any' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = ?',
			'get_record' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = ? AND `type` IN (?, \'CNAME\',\'ZONE\')',
			'get_authority' => 'SELECT * FROM `zone_records` WHERE `zone` = ? AND `host` = \'\' AND `type` IN (?)',
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

	protected function buildInternetQuestionReply($pkt, $host, $zone, $domain, $type, $subquery = 0, $initial_query = NULL) {
		$ohost = $host;
		if ($ohost != '') $ohost .= '.';
		$typestr = Type::typeToString($type);

		while(1) {
			// got host & domain, lookup...
			if ($type != Type\RFC1035::TYPE_ANY) {
				$res = $this->sql_stmts['get_record']->run(array($zone, strtolower($host), $typestr));
			} else {
				$res = $this->sql_stmts['get_record_any']->run(array($zone, strtolower($host)));
			}

			$found = 0;
			$add_lookup = array();
			$res_list = array();
			while($row = $res->fetch_assoc())
				$res_list[] = $row;
			foreach($res_list as $row) {
				++$found;

				if (strtolower($row['type']) == 'zone') {
					// special type: linking to another zone
					$link_zone = $this->sql_stmts['get_zone']->run(array(strtolower($row['data'])))->fetch_assoc();
					if ($link_zone) {
						$this->buildInternetQuestionReply($pkt, substr($ohost, 0, -1), $link_zone['zone_id'], $domain, $type, $subquery, $initial_query);
					}
					continue;
				}

				$answer = $this->makeResponse($row, $pkt);
				if (is_null($answer)) continue;

				if ($answer->getType() == Type\RFC1035::TYPE_CNAME) {
					$aname = $row['data'];
					if (substr($aname, -1) != '.') $aname .= '.' . $domain . '.';
					if (strtolower($aname) != strtolower($ohost . $domain. '.')) {
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

		if ($subquery < 5) {
			foreach($add_lookup as $aname) {
				$this->handleInternetQuestion($pkt, $aname, $type, $subquery + 1, $initial_query);
			}
		} elseif($add_lookup) {
			Logger::log(Logger::LOG_WARN, 'Query reached recursivity limit (query against '.$initial_query.' reaching '.$name.' and with lookup of '.implode(', ', $add_lookup).')');
		}

		if ($subquery) return;

		// add authority
		$res = $this->sql_stmts['get_authority']->run(array($zone, $pkt->hasAnswer()?'NS':'SOA'));

		while($row = $res->fetch_assoc()) {
			$answer = $this->makeResponse($row, $pkt);
			if (is_null($answer)) continue;
			if (!$pkt->hasAnswer()) $row['ttl'] = 0; // trick to avoid remote dns daemon caching the fact that this doesn't exists
			$pkt->addAuthority($domain . '.', $answer, $row['ttl']);
		}
	}

	protected function handleInternetQuestion($pkt, $name, $type, $subquery = 0, $initial_query = NULL) {
		// strip ending "."
		if (substr($name, -1) == '.') $name = substr($name, 0, -1);

		if (is_null($initial_query)) $initial_query = $name;

		// check sql statements
		if (is_null($this->sql_stmts)) {
			if (!$this->prepareStatements()) return;
		}

		$typestr = Type::typeToString($type);
		if (is_null($typestr)) {
			$pkt->setFlag('rcode', Packet::RCODE_NOTIMP);
			return;
		}

		if (strtolower($name) == 'my.dns.st') {
			// HACK HACK HACK
			$pkt->setFlag('aa', 1);
			$pkt->setFlag('ra', 0);
			
			$peer = $pkt->getPeer();
			if (!is_array($peer)) $peer = explode(':', $peer);
			switch($type) {
				case Type\RFC1035::TYPE_A: 
					$answer = $this->$this->makeResponse(array('type' => 'A', 'data' => $peer[0]), $pkt);
					$pkt->addAnswer($name.'.', $answer, 600);
					break;
				case Type\RFC1035::TYPE_TXT:
					$answer = $this->$this->makeResponse(array('type' => 'TXT', 'data' => implode(' ', $peer)), $pkt);
					$pkt->addAnswer($name.'.', $answer, 600);
					break;
				case Type\RFC1035::TYPE_ANY:
					$answer = $this->$this->makeResponse(array('type' => 'A', 'data' => $peer[0]), $pkt);
					$pkt->addAnswer($name.'.', $answer, 600);
					$answer = $this->$this->makeResponse(array('type' => 'TXT', 'data' => implode(' ', $peer)), $pkt);
					$pkt->addAnswer($name.'.', $answer, 600);
					break;
			}
			return;
		}

		// search this domain
		$domain = $name;
		$host = '';
		while(1) {
			$res = $this->sql_stmts['get_domain']->run(array(strtolower($domain)))->fetch_assoc();
			if (!$res) {
				$pos = strpos($domain, '.');
				if ($pos === false) {
					if (!$subquery) {
						$pkt->setFlag('rcode', Packet::RCODE_REFUSED); // We do not want to resolve you (won't recursive resolve)
						$pkt->setFlag('ra', 0);
					}
					return;
				}
				$host .= ($host==''?'':'.').substr($domain, 0, $pos);
				$domain = substr($domain, $pos + 1);
				continue;
			}

			break;
		}

		$pkt->setFlag('aa', 1);
		$pkt->setFlag('ra', 0);
		$this->IPC->callPort('DNSd::DbEngine::'.$this->sql->unique(), 'domainHit', array($domain), false); // do not wait for reply

		$zone = $res['zone'];

		$pkt->setDefaultDomain($domain);

		$this->buildInternetQuestionReply($pkt, $host, $zone, $domain, $type, $subquery, $initial_query);
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
		$pkt = new $this->packet_class($peer_info);
		if (!$pkt->decode($data)) return;

		$pkt->resetAnswer();

		foreach($pkt->getQuestions() as $question) {
			$this->handleQuestion($pkt, $question);
		}

		$pkt->setFlag('qr', 1);

		$pkt = $pkt->encode();
		//$test = new $this->packet_class();
		//$test->decode($pkt);
		//var_dump($test);

		if (!is_null($pkt)) $this->parent->sendReply($pkt, $peer_info);
	}
}

