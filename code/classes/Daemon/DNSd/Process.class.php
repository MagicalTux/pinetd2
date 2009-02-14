<?php

namespace Daemon\DNSd;

use pinetd\Timer;
use pinetd\Logger;

class Process extends \pinetd\Process {
	private $master_link = NULL;

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
	}

	public function checkMaster(&$extra = null) {
		if (!isset($this->localConfig['Master'])) return true;
		var_dump($this->localConfig['Name']['_']);
		exit;

		if (is_null($this->master_link)) {
			Logger::log(Logger::LOG_NOTICE, 'Connecting to DNSd master');

			$config = $this->localConfig['Master'];

			$this->master_link = stream_socket_client('tcp://'.$config['Host'].':'.$config['Port'], $errno, $errstr, 4);

			if (!$this->master_link) {
				$this->master_link = NULL;
				Logger::log(Logger::LOG_WARN, 'Could not connect to DNSd master: ['.$errno.'] '.$errstr);
				return true;
			}

			// send introduction packet
			$pkt = $this->localConfig['Name']['_'] . pack('N', time());
			$pkt .= sha1($pkt.$config['Signature'], true);
			$pkt = 'BEGIN'.$pkt;
			fputs($this->master_link, $pkt);
		}

//		var_dump($this->localConfig['Master']);
/*
  ["Signature"]=>
	  string(6) "qwerty"
		  ["Host"]=>
			  string(9) "localhost"
				  ["Name"]=>
					  string(11) "GiveMeAName"
						  ["Port"]=>
							  string(5) "10053"
								*/

		return true;
	}

	public function mainLoop() {
		parent::initMainLoop();

		$tcp = $this->IPC->openPort('DNSd::TCPMaster');

		$class = relativeclass($this, 'DbEngine');
		$this->db_engine = new $class($this->localConfig, $tcp);
		$this->IPC->createPort('DNSd::DbEngine', $this->db_engine);

		Timer::addTimer(array($this, 'checkMaster'), 5, $foo = NULL, true);
		$this->checkMaster();

		while(1) {
			$this->IPC->selectSockets(200000);
			Timer::processTimers();
		}
	}

	public function shutdown() {
	}
}

