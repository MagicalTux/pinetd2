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

namespace pinetd\UDP;

use pinetd\Logger;
use pinetd\SQL;
use pinetd\IPC;

abstract class Base extends \pinetd\DaemonBase {
	protected $port;
	protected $daemon;
	protected $IPC;
	private $socket;
	private $lastSocket;
	protected $protocol = 'tcp';
	protected $TLS = false;

	abstract protected function handlePacket($pkt, $peer);

	public function __construct($port, $daemon, &$IPC, $node) {
		$this->port = $port;
		$this->daemon = $daemon;
		$this->IPC = &$IPC;
		// create tcp server socket
		$this->loadLocalConfig($node);
		$ip = $this->localConfig['Network']['Bind']['Ip']['_'];
		if (isset($this->daemon['Ip'])) $ip = $this->daemon['Ip'];
		Logger::log(Logger::LOG_INFO, 'Loading '.get_class($this).' on port '.$port.', bound to ip '.$ip);
		$context = stream_context_create(array());
		if ($this->daemon['SSL']) {
			$cert_name = $this->daemon['SSL'];
			if ($cert_name[0] == '~') {
				$cert_name = substr($cert_name, 1);
				$this->TLS = true;
			} else {
				throw new Exception('ERROR: can\'t make an UDP server use native SSL');
			}
			$cert = $this->IPC->loadCertificate($cert_name);
			if (!$cert) {
				throw new Exception('ERROR: Trying to give me a non-existant certificate');
			}
//			stream_context_set_option($context, array('ssl' => $cert));
		} else {
			$cert = $this->IPC->loadCertificate(strtolower($this->daemon['Service']));
			// ... ?
		}
		$this->protocol = 'udp';
		$this->socket = @stream_socket_server('udp://'.$ip.':'.$this->daemon['Port'], $errno, $errstr, STREAM_SERVER_BIND, $context);
		if (!$this->socket) {
			throw new \Exception('Error creating listening socket: ['.$errno.'] '.$errstr);
		}
		$this->IPC->registerSocketWait($this->socket, array(&$this, 'doRecv'), $foo = array(&$this->socket));
	}

	public function doRecv($sock) {
		// ok, got a socket, we want to recv from it :)
		$pkt = stream_socket_recvfrom($sock, 65535, 0, $peer);
		if ($pkt === false) return; // :(

		$this->lastSocket = $sock;

		$this->handlePacket($pkt, $peer);
	}

	protected function sendPacket($pkt, $to) {
		return stream_socket_sendto($this->lastSocket, $pkt, 0, $to);
	}

	public function shutdown() {
		fclose($this->socket);
	}

	public function quit() {
		$this->shutdown();
		$this->IPC->killSelf();
		$this->IPC->ping();
		exit;
	}

	public function mainLoop() {
		// We are in a "own fork" if we reach this, so let's rename our process!
		if (defined('PINETD_GOT_PROCTITLE')) {
			setproctitle('UDP: '.get_class($this).' on port '.$this->port);
		}
		while(1) {
			$this->IPC->selectSockets(200000);
			$this->processTimers();
		}
	}
}

