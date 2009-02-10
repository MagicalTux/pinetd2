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

class Client extends \pinetd\ProcessChild {
	protected $fd;
	protected $peer;
	private $send_end = '';
	protected $buf = '';
	protected $ok = true;
	protected $protocol = 'tcp';
	protected $timeout = 3600; // default: 1 hour timeout

	public function __construct($fd, $peer, $parent, $protocol) {
		$this->fd = $fd;
		$this->peer = explode(':', $peer); // ip:port TODO: handle ipv6
		$this->protocol = $protocol;
		parent::__construct($parent);
//		var_dump(stream_filter_register('pinetd.autossl', 'pinetd\\TCP\\AutoSSL'));
//		var_dump(stream_filter_prepend($this->fd, 'pinetd.autossl', STREAM_FILTER_READ));
	}

	public function doResolve() {
		if (!is_null($this->dns)) return;
		$this->peer[2] = gethostbyaddr($this->peer[0]);
	}

	public function shutdown() {
		// (void)
	}

	public function getHostName() {
		return $this->peer[2];
	}

	public function setMsgEnd($str) {
		$this->send_end = $str;
	}

	public function sendMsg($msg) {
		if (!$this->ok) return false;
		return fwrite($this->fd, $msg . $this->send_end);
	}

	public function close() {
		Logger::log(Logger::LOG_DEBUG, 'Client from '.$this->peer[0].' is being closed');
		$this->ok = false;
		$this->IPC->killSelf($this->fd);
		return fclose($this->fd);
	}

	protected function parseLine($lin) {
		$lin = rtrim($lin); // strip potential \r and \n
		$argv = preg_split('/\s+/', $lin);
		$fullarg = ltrim(substr($lin, strlen($argv[0])));
		$cmd = '_cmd_'.strtolower($argv[0]);
		if (!method_exists($this, $cmd)) $cmd = '_cmd_default';
		return $this->$cmd($argv, $cmd, $fullarg);
	}

	protected function parseBuffer() {
		while($this->ok) {
			$pos = strpos($this->buf, "\n");
			if ($pos === false) break;
			$pos++;
			$lin = substr($this->buf, 0, $pos);
			$this->buf = substr($this->buf, $pos);
			$this->parseLine($lin);
		}
		$this->setProcessStatus(); // back to idle
	}

	// overload this to add an action on timeout (eg. sending a msg to client). Please return true
	protected function socketTimedOut() {
		// void
		return true;
	}

	protected function pullDataFromSocket() {
		stream_set_timeout($this->fd, $this->timeout);
		$res = fread($this->fd, 8192);
		$info = stream_get_meta_data($this->fd);

		if ($info['timed_out']) {
			$this->socketTimedOut();
			return false;
		}
		
		return $res;
	}

	public function readData() {
		$dat = $this->pullDataFromSocket();
		if (($dat === false) || ($dat === '')) {
			Logger::log(Logger::LOG_INFO, 'Lost client from '.$this->peer[0]);
			$this->IPC->killSelf($this->fd);
			$this->ok = false;
			return;
		}
		$this->buf .= $dat;
		$this->parseBuffer();
	}

	public function readLine() {
		$dat = $this->pullDataFromSocket();
		if (($dat === false) || ($dat === '')) {
			Logger::log(Logger::LOG_INFO, 'Lost client from '.$this->peer[0]);
			$this->IPC->killSelf($this->fd);
			$this->ok = false;
			throw new Exception('Client lost');
		}
		$this->buf .= $dat;
		$pos = strpos($this->buf, "\n");
		if ($pos === false) {
			$res = $this->buf;
			$this->buf = '';
			return $res;
		}
		$pos++;
		$lin = substr($this->buf, 0, $pos);
		$this->buf = substr($this->buf, $pos);
		return $lin;
	}

	protected function setProcessStatus($msg = '') {
		if (!defined('PINETD_GOT_PROCTITLE')) return;
		if (!$msg) $msg = 'idle';

		if ((isset($this->peer[2])) && ($this->peer[0] != $this->peer[2])) {
			setproctitle('[' . $this->peer[0] . '] ' . get_class($this) . ': ' . $msg . ' (' . $this->peer[2].')');
			return;
		}

		setproctitle('['.$this->peer[0].'] ' . get_class($this) . ': ' . $msg);
	}

	public function mainLoop($IPC) {
		$this->IPC = $IPC;
		$this->IPC->setParent($this);
		$this->IPC->registerSocketWait($this->fd, array($this, 'readData'), $foo = array());
		$this->setProcessStatus('resolving');
		$this->doResolve();
		$this->sendBanner();
		$this->setProcessStatus();
		while($this->ok) {
			$IPC->selectSockets(200000);
			$this->processTimers();
		}
		exit;
	}
}


