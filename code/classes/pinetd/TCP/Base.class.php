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

namespace pinetd\TCP;

use pinetd\Logger;
use pinetd\SQL;
use pinetd\IPC;
use pinetd\Timer;
use \Exception;

abstract class Base extends \pinetd\DaemonBase {
	protected $port;
	protected $daemon;
	protected $IPC;
	protected $socket;
	protected $clients = array();
	protected $fclients = array(); // forked clients
	protected $protocol = 'tcp';
	protected $TLS = false;

	public function __construct($port, $daemon, &$IPC, $node) {
		$this->port = $port;
		$this->daemon = $daemon;
		$this->IPC = &$IPC;
		// create tcp server socket
		$this->loadLocalConfig($node);
		$ip = $this->localConfig['Network']['Bind']['Ip']['_'];
		if (isset($this->daemon['Ip'])) $ip = $this->daemon['Ip'];
		Logger::log(Logger::LOG_INFO, 'Loading '.get_class($this).' on port '.$port.', bound to ip '.$ip);
		$protocol = 'tcp';
		$context = stream_context_create(array());
		if ($this->daemon['SSL']) {
			$cert_name = $this->daemon['SSL'];
			if ($cert_name[0] == '~') {
				$cert_name = substr($cert_name, 1);
				$this->TLS = true;
			} else {
				$protocol = 'ssl';
			}
			$cert = $this->IPC->loadCertificate($cert_name);
			if (!$cert) {
				throw new Exception('ERROR: Trying to give me a non-existant certificate');
			}
			stream_context_set_option($context, array('ssl' => $cert));
		}
		$cert = $this->IPC->loadCertificate(strtolower($this->daemon['Service']));
		$this->protocol = $protocol;
		$this->socket = @stream_socket_server($protocol.'://'.$ip.':'.$this->daemon['Port'], $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
		if (!$this->socket) {
			throw new Exception('Error creating listening socket: ['.$errno.'] '.$errstr);
		}
		$this->IPC->registerSocketWait($this->socket, array(&$this, 'doAccept'), $foo = array(&$this->socket));
	}

	public function _ChildIPC_hasTLS() {
		return $this->hasTLS();
	}

	public function hasTLS() {
		return $this->TLS;
	}

	public function _ChildIPC_canSUID() {
		if (!PINETD_CAN_SUID) return false;
		if (!isset($this->localConfig['Security']['SUID'])) return false;
		return $this->localConfig['Security']['SUID'];
	}

	public function canSUID() {
		return false;
	}

	public function _ChildIPC_canChroot() {
		if (!PINETD_CAN_CHROOT) return false;
		if (!isset($this->localConfig['Security']['Chroot'])) return false;
		return true;
	}

	public function canChroot() {
		return false;
	}

	function spawnClient($socket, $peer, $parent, $protocol) {
		$class = relativeclass($this, 'Client');
		return new $class($socket, $peer, $parent, $protocol);
	}

	public function shutdown() {
		Logger::log(Logger::LOG_INFO, 'Parent is killing its child on port '.$this->daemon['Port']);
		foreach($this->fclients as $pid => $data) {
			// todo: close all children
			$data['IPC']->stop();
		}
		foreach($this->clients as $port => $data) {
			$data['obj']->shutdown();
			$data['obj']->close();
			unset($this->clients[$port]);
		}
		fclose($this->socket);
	}

	public function quit() {
		$this->shutdown();
		$this->IPC->killSelf();
		$this->IPC->ping();
		exit;
	}

	public function doAccept($sock) {
		$news = @stream_socket_accept($sock, 0, $peer);
		if (!$news) return;
		$new = $this->spawnClient($news, $peer, $this, $this->protocol);
		if (!$new) {
			@fclose($news);
			return;
		}
		if (!$new->welcomeUser()) {
			$new->close();
			return;
		}
		Logger::log(Logger::LOG_DEBUG, 'Accepting new client on port '.$this->daemon['Port'].' from '.$peer);
		if ((isset($this->localConfig['Security']['Fork'])) && PINETD_CAN_FORK) {
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
			$pid = pcntl_fork();
			if ($pid > 0) {
				// we are parent
				fclose($news);
				fclose($pair[1]);
				unset($new);
				SQL::parentForked();
				$this->fclients[$pid] = array(
					'pid' => $pid,
					'peer' => $peer,
					'socket' => $pair[0],
					'IPC' => new \pinetd\IPC($pair[0], false, $this, $this->IPC),
					'connect' => time(),
				);
				$this->IPC->registerSocketWait($pair[0], array($this->fclients[$pid]['IPC'], 'run'), $foobar = array(&$this->fclients[$pid]));
				return;
			}
			if ($pid == 0) {
				// we are child
				SQL::forked();
				Timer::reset();
				foreach($this->clients as $c) fclose($c['fd']);
				fclose($this->socket);
				fclose($pair[0]);
				$IPC = new IPC($pair[1], true, $foo = null, $bar = null);
				$IPC->ping(); // wait for parent to be ready
				Logger::setIPC($IPC);
				Logger::log(Logger::LOG_DEBUG, 'Daemon started for client, pid '.getmypid());
				$new->mainLoop($IPC);
				$IPC->Error('Exited from main loop!', 0);
				exit;
			}
			fclose($pair[0]);
			fclose($pair[1]);
		}
		$this->clients[$news] = array(
			'socket' => $news,
			'obj' => $new,
			'peer' => $peer,
			'connect' => time(),
		);
		$new->doResolve();
		$new->sendBanner();
		$this->IPC->registerSocketWait($news, array($new, 'readData'), $foo = array());
	}

	public function _ChildIPC_killSelf(&$daemon, $fd) {
		$daemon['IPC']->stop();
		unset($this->fclients[$daemon['pid']]);
	}

	public function killSelf($fd) {
		if (!isset($this->clients[$fd])) return;
		unset($this->clients[$fd]);
		$this->IPC->removeSocket($fd);
	}

	public function IPCDied($fd) {
//		$info = $this->IPC->getSocketInfo($fd);
		$this->IPC->removeSocket($fd);
//		$info = &$this->fclients[$info['pid']];
//		Logger::log(Logger::LOG_WARN, 'IPC for '.$info['pid'].' died');
	}

	/* make sure children are alive, and clean up them if needed :) */
	public function waitChildren() {
		if (!PINETD_CAN_FORK) return;
		if (count($this->fclients) == 0) return; // nothing to do
		$res = pcntl_wait($status, WNOHANG);
		if ($res == -1) return; // something bad happened
		if ($res == 0) return; // no process terminated
		// search what ended
		$ended = $this->fclients[$res];
		if (is_null($ended)) return; // we do not know what ended
		if (pcntl_wifexited($status)) {
			$code = pcntl_wexitstatus($status);
			Logger::log(Logger::LOG_DEBUG, 'Client with pid #'.$res.' exited');
			unset($this->fclients[$res]);
			return;
		}
		if (pcntl_wifstopped($status)) {
			Logger::log(Logger::LOG_INFO, 'Waking up stopped child on pid '.$res);
			posix_kill($res, SIGCONT);
			return;
		}
		if (pcntl_wifsignaled($status)) {
			$signal = pcntl_wtermsig($status);
			$const = get_defined_constants(true);
			$const = $const['pcntl'];
			foreach($const as $var => $val) {
				if (substr($var, 0, 3) != 'SIG') continue;
				if (substr($var, 0, 4) == 'SIG_') continue;
				if ($val != $signal) continue;
				$signal = $var;
				break;
			}
			Logger::log(Logger::LOG_INFO, 'Client with pid #'.$res.' died due to signal '.$signal);
			unset($this->fclients[$res]);
		}
	}

	public function mainLoop() {
		// We are in a "own fork" if we reach this, so let's rename our process!
		if (defined('PINETD_GOT_PROCTITLE')) {
			setproctitle('TCP: '.get_class($this).' on port '.$this->port);
		}
		while(1) {
			$this->IPC->selectSockets(200000);
			$this->waitChildren();
			$this->processTimers();
		}
	}
}

