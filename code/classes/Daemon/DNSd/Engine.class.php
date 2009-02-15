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

	public function __construct($parent, $IPC, $localConfig) {
		$this->parent = $parent;
		$this->IPC = $IPC;
		$this->packet_class = relativeclass($this, 'Packet');

		$this->localConfig = $localConfig;
		// check table struct
		$this->sql = SQL::Factory($this->localConfig['Storage']);
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

		// TODO: create a function to convert a "TYPE" to "TYPESTR"
		$typestr = Type::typeToString($type);
		if (is_null($typestr)) return NULL;

		// search this domain
		$domain = $name;
		$host = '';
		while(1) {
			$res = $this->sql->query('SELECT `zone` FROM `domains` WHERE `domain` = '.$this->sql->quote_escape($domain))->fetch_assoc();
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
			$req = 'SELECT * FROM `zone_records` WHERE `zone` = '.$this->sql->quote_escape($zone).' AND `host` = '.$this->sql->quote_escape($host);
			if ($type != Type\RFC1035::TYPE_ANY)
				$req.= ' AND `type` IN ('.$this->sql->quote_escape($typestr).', \'CNAME\')';
			$res = $this->sql->query($req);

			$found = 0;
			while($row = $res->fetch_assoc()) {
				++$found;

				$answer = $this->makeResponse($row, $pkt);
				if (is_null($answer)) continue;

				if ($answer->getType() == Type\RFC1035::TYPE_CNAME) {
					$aname = $row['data'];
					if (substr($aname, -1) != '.') $aname .= '.' . $domain . '.';
					if (strtolower($aname) != $ohost . $domain. '.') {
						$pkt->addAnswer($ohost. $domain. '.', $answer, $row['ttl']);
						$this->handleInternetQuestion($pkt, $aname, $type, true);
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

		if ($subquery) return;

		// add authority
		$req = 'SELECT * FROM `zone_records` WHERE `zone` = '.$this->sql->quote_escape($zone).' AND `host` = \'\' AND `type` IN (\'NS\')';
		$res = $this->sql->query($req);

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

