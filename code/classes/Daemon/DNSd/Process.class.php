<?php

namespace Daemon\DNSd;

use pinetd\Timer;
use pinetd\Logger;

class Process extends \pinetd\Process {
	private $master_link = NULL;
	private $status = 0;
	private $buf = '';
	private $db_engine;

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
	}

	public function pingMaster(&$extra = null) {
		if (!is_null($this->master_link)) $this->sendPkt('Ping');
	}

	public function checkMaster(&$extra = null) {
		if (!isset($this->localConfig['Master'])) return true;

		if (is_null($this->master_link)) {
			Logger::log(Logger::LOG_INFO, 'Connecting to DNSd master');

			$config = $this->localConfig['Master'];

			$this->master_link = @stream_socket_client('tcp://'.$config['Host'].':'.$config['Port'], $errno, $errstr, 4);

			if (!$this->master_link) {
				$this->master_link = NULL;
				Logger::log(Logger::LOG_WARN, 'Could not connect to DNSd master: ['.$errno.'] '.$errstr);
				return true;
			}

			// send introduction packet
			$pkt = $this->localConfig['Name']['_'] . pack('N', time());
			$pkt .= sha1($pkt.$config['Signature'], true);
			$pkt = 'BEGIN'.$pkt;
			fputs($this->master_link, pack('n', strlen($pkt)) . $pkt);
			$this->status = 0;
			$this->buf = '';

			$this->IPC->registerSocketWait($this->master_link, array($this, 'readData'), $foo = array());
		}
		return true;
	}

	protected function receivePacket($pkt) {
		if ($this->status == 0) {
			// check if we got what we want
			if ($pkt == 'BAD') {
				$this->IPC->removeSocket($this->master_link);
				fclose($this->master_link);
				Logger::log(Logger::LOG_WARN, 'Connection handshake refused by DNSd master (please check master log for details)');
				$this->master_link = NULL;
				return;
			}

			// check stuff about master
			$config = $this->localConfig['Master'];

			$good_sign = sha1(substr($pkt, 0, -20).$config['Signature'], true);
			if ($good_sign != substr($pkt, -20)) {
				Logger::log(Logger::LOG_WARN, 'Got bad signature from master, this is fishy!');
				$this->IPC->removeSocket($this->master_link);
				fclose($this->master_link);
				$this->master_link = NULL;
				return;
			}
			
			// strip signature
			$pkt = substr($pkt, 0, -20);

			// check timestamp
			list(,$stamp) = unpack('N', substr($pkt, -4));
			if (abs(time() - $stamp) > 5) {
				Logger::log(Logger::LOG_WARN, 'DNSd master is reporting bad timestamp, please look for forged packets and master/slave time synch (hint: install ntpd on both)!');
				$this->IPC->removeSocket($this->master_link);
				fclose($this->master_link);
				$this->master_link = NULL;
				return;
			}

			// check server name
			$master = substr($pkt, 0, -4);
			if ($master != $config['Name']) {
				Logger::log(Logger::LOG_WARN, 'DNSd master is note reporting the same name as the one found in config (hint: pay attention to case)');
				$this->IPC->removeSocket($this->master_link);
				fclose($this->master_link);
				$this->master_link = NULL;
				return;
			}

			$this->status = 1;

			// Get most recent change in tables
			$recent = $this->db_engine->lastUpdateDate();

			$this->sendPkt('DoSync', array($recent));

			return;
		}
		$pkt = @unserialize($pkt);
		switch($pkt['type']) {
			case 'dispatch':
				$data = $pkt['data'];
				$this->db_engine->processUpdate($data[0], $data[1]);
				break;
			case 'pong':
				// TODO: compute latency and let user know the master/slave latency :)
		}
	}

	public function domainHit($domain, $hit_count) {
		return $this->sendPkt('domainHit', array($domain, $hit_count));
	}

	protected function sendPkt($cmd, array $params = array()) {
		$data = serialize(array($cmd, $params));

		if (strlen($data) > 65535) return false;

		return fwrite($this->master_link, pack('n', strlen($data)).$data);
	}

	protected function parseBuffer() {
		while(!is_null($this->master_link)) {
			if (strlen($this->buf) < 2) break;
			list(,$len) = unpack('n', $this->buf);

			if (strlen($this->buf) < (2+$len)) break;

			$dat = substr($this->buf, 2, $len);
			$this->buf = substr($this->buf, $len+2);
			$this->receivePacket($dat);
		}
	}

	public function readData() {
		$dat = fread($this->master_link, 4096);
		if (($dat === false) || ($dat === '')) {
			Logger::log(Logger::LOG_WARN, "Lost link with DNSd master");
			$this->IPC->removeSocket($this->master_link);
			fclose($this->master_link);
			$this->master_link = NULL;
		}
		$this->buf .= $dat;
		$this->parseBuffer();
	}

	public function mainLoop() {
		parent::initMainLoop();

		$tcp = $this->IPC->openPort('DNSd::TCPMaster');

		$class = relativeclass($this, 'DbEngine');
		$this->db_engine = new $class($this, $this->localConfig, $tcp);
		$this->IPC->createPort('DNSd::DbEngine', $this->db_engine);

		Timer::addTimer(array($this, 'checkMaster'), 5, $foo = NULL, true);
		Timer::addTimer(array($this, 'pingMaster'), 120, $foo = NULL, true);
		$this->checkMaster();

		while(1) {
			$this->IPC->selectSockets(200000);
			Timer::processTimers();
		}
	}

	public function shutdown() {
	}
}

