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
/**
 * \file IPC.class.php
 * \brief IPC (Inter-Process Communications) code
 */

namespace pinetd;

/**
 * \class IPC
 * \brief IPC (Inter-Process Communications) class, used by parents and children
 */
class IPC {
	private $pipe; /*!< Pipe to my child/parent */
	private $ischld; /*!< Am I a child?? */
	private $buf = ''; /*!< Input buffer (reads are non blocking) */
	private $parent = NULL; /*!< My "parent" class, where functions are called */
	private $fds = array(); /*!< List of fds to listens, and callbacks */

	const CMD_PING = 'PING'; /*!< PING command, should receive RES_PING from peer */
	const CMD_NOOP = 'NOOP'; /*!< NOOP command, just to NOOP (NB: this is virtual, should never be sent) */
	const CMD_STOP = 'STOP'; /*!< STOP a child, only parent can send this */
	const CMD_LOG = 'LOG'; /*!< LOG data to the log subsystem */
	const CMD_ERROR = 'ERR'; /*!< Some kind of error occured, for example the system died */
	const CMD_CALL = 'CALL'; /*!< CALL a peer function. This will call a function on peer's class */

	const RES_PING = 'PONG'; /*!< PONG, the reply to ping */
	const RES_CALL = 'RCALL'; /*!< Reply to a CMD_CALL, containing the result of the called function */
	const RES_CALL_EXCEPT = 'RCALLX'; /*!< A CMD_CALL resulted in an exception to be thrown */

	/**
	 * \brief IPC constructor
	 * \param $pipe Pipe to remote parent/child
	 * \param $ischld bool Am I a child?
	 * \param $parent If not a child, put $this here (reference to parent when parent)
	 */
	function __construct($pipe, $ischld, &$parent) {
		$this->pipe = $pipe;
		$this->ischld = $ischld;
		if (!$this->ischld) { // parent
			stream_set_blocking($this->pipe, false); // parent should not hang
			$this->parent = &$parent;
		} else {
			$this->registerSocketWait($pipe, array($this, 'run'), $foobar = array(null));
		}
	}
	
	/**
	 * \brief call a function on remote parent/child
	 * \param $func Function name (will be prepended with some text)
	 * \param $args Argument list for this function
	 * \return mixed data, depending on called function
	 */
	function __call($func, $args) {
		array_unshift($args, $func);
		$this->sendcmd(self::CMD_CALL, $args);
		if (!$this->ischld) return null;
		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			$cmd = $this->readcmd();
			// TODO: handle exceptions too?
			if ($cmd[0] == self::RES_CALL_EXCEPT) {
				throw $cmd[1]; // throw exception again in this process
			} elseif ($cmd[0] != self::RES_CALL) {
				$this->handlecmd($cmd, $foo = null);
			} else {
				$res = $cmd[1];
				break;
			}
		}
		return $res;
	}

	/**
	 * \brief register a wait on a socket, with a callback function and some data by reference
	 * \param $fd File descriptor to be select()ed
	 * \param $callback Callback function (can be a class, see PHP doc)
	 * \param $data Data to be passed to the callback, by reference
	 */
	function registerSocketWait($fd, $callback, &$data) {
		if (!is_array($data)) throw new Exception('No data defined :(');
		$this->fds[$fd] = array('fd'=>$fd, 'callback' => $callback, 'data' => &$data);
	}

	/**
	 * \brief Get callback data by socket
	 * \param $fd The fd to be checked
	 * \return mixed data or null if fd not known
	 */
	function getSocketInfo($fd) {
		return $this->fds[$fd]['data'];
	}

	/**
	 * \brief Stop listening on a stream
	 * \param $fd to stop listening on
	 */
	function removeSocket($fd) {
		unset($this->fds[$fd]);
	}

	/**
	 * \brief Define parent class for this IPC. Works only once
	 * \param $parent The parent class
	 */
	public function setParent(&$parent) {
		if (is_null($this->parent)) $this->parent = &$parent;
	}

	/**
	 * \brief Send a command to peer
	 * \param $cmd Command name
	 * \param ... Parameters
	 * \internal
	 */
	private function sendcmd() {
		$cmd = func_get_args();
		$cmd = serialize($cmd);
		@fwrite($this->pipe, pack('L', strlen($cmd)).$cmd);
		//@fflush($this->pipe);
	}

	/**
	 * \brief Read data from buffer and decode it
	 * \return First packet in queue
	 * \internal
	 */
	private function readbuf() {
		if (strlen($this->buf) < 4) return array(self::CMD_NOOP);
		list(,$len) = unpack('L', substr($this->buf, 0, 4));
		if ($len > (strlen($this->buf)-4)) return array(self::CMD_NOOP);
		$tmp = substr($this->buf, 4, $len);
		$tmp = unserialize($tmp);
		$this->buf = (string)substr($this->buf, 4+$len);
		return $tmp;
	}

	/**
	 * \brief Read data from remote buffer if local buffer is empty, and return last packet
	 * \return First packet in queue
	 * \internal
	 */
	private function readcmd() {
		$res = $this->readbuf();
		if ($res[0] != self::CMD_NOOP) return $res;
		stream_set_blocking($this->pipe, false);
		$tmp = @fread($this->pipe, 1024);
		if (($tmp === '') && feof($this->pipe)) return null;
		if (!is_string($tmp)) return null;
		if (strlen($tmp) == 0) {
			return $this->readbuf();
		}
		$this->buf .= $tmp;
		return $this->readbuf();
	}

	/**
	 * \brief Handle a command request
	 * \param $cmd array The command to handle
	 * \param $daemon Which child caused this call (if child), for CMD_CALL
	 * \internal
	 */
	private function handlecmd($cmd, &$daemon) {
		if ($cmd === false) return;
		if (is_null($cmd)) {
			if (!$this->parent) throw new Exception('Peer got away');
			$this->removeSocket($this->pipe);
//			unset($this->fds[$this->pipe]);
			$this->parent->IPCDied($this->pipe);
			return;
		}
		switch($cmd[0]) {
			case self::CMD_NOOP:
				return true;
			case self::CMD_PING:
				$this->sendcmd(self::RES_PING, $cmd[1]);
				break;
			case self::CMD_CALL:
				// $cmd[1] = array(function, args)
				$key = ($this->ischld)?'_ParentIPC_':'_ChildIPC_';
				$func = array(&$this->parent,$key.$cmd[1][0]);
				if (!method_exists($this->parent, $key.$cmd[1][0])) {
					Logger::log(Logger::LOG_ERR, 'Tried to call '.get_class($func[0]).'::'.$func[1].' but failed!');
					if (!$this->ischld) $this->sendcmd(self::RES_CALL, null); // avoid deadlock
					break;
				}
				$cmd[1][0] = &$daemon;
				try {
					$res = call_user_func_array($func, $cmd[1]);
				} catch(Exception $e) {
					if(!$this->ischld) $this->sendcmd(self::RES_CALL_EXCEPT, $e);
					break;
				}
				if(!$this->ischld) $this->sendcmd(self::RES_CALL, $res);
				break;
			case self::CMD_ERROR:
				if ($cmd[1][1] > 0) {
					$daemon['deadline'] = time()+$cmd[1][1];
				}
				break;
			case self::CMD_STOP:
				$this->parent->shutdown();
				exit;
			case self::CMD_LOG:
				call_user_func_array(array('pinetd::Logger', 'log_full'), $cmd[1]);
				break;
			default:
				throw new Exception('Unknown command '.$cmd[0]);
		}
		return true;
	}

	/**
	 * \brief Pass log messages to parent
	 * \internal
	 */
	public function log() {
		if (!$this->ischld) throw new Exception('Parent sending logs to child makes no sense');
		$n = func_get_args();
		return $this->sendcmd(self::CMD_LOG, $n);
	}

	/**
	 * /brief Stop attached child
	 */
	public function stop() {
		if ($this->ischld) throw new Exception('Children can\'t tell their parents to go to sleep');
		return $this->sendcmd(self::CMD_STOP);
	}

	/**
	 * \brief Send a CMD_PING. Will wait for RES_PING only if child
	 */
	public function ping() {
		$this->sendcmd(self::CMD_PING, microtime(true));
		if (!$this->ischld) return true;
		while(!feof($this->pipe)) {
			$cmd = $this->readcmd();
			if ($cmd[0] != self::RES_PING) {
				$this->handlecmd($cmd, $foo = null);
			} else {
				break;
			}
		}
		return true;
	}

	/**
	 * \brief Wait for something to happen (or for timeout) and handle it
	 * \param $timeout Timeout for select() in microseconds
	 * \return int Number of processed sockets
	 */
	public function selectSockets($timeout) {
		$r = array();
		foreach($this->fds as &$c) $r[] = $c['fd'];
		$n = @stream_select($r, $w=null, $e=null, 0, $timeout);
		if (($n==0) && (count($r)>0)) $n = count($r); // stream_select returns weird values sometimes Oo
		if ($n<=0) return $n;
		foreach($r as $fd) {
			$info = &$this->fds[$fd];
			// somewhat dirty (but not so dirty) workaround for a weird PHP 5.3 behaviour
			if ( (is_array($info['callback'])) && ($info['callback'][1] == 'run')) {
				// This function expects one parameter, and by ref
				$ref = &$info['data'][0];
				call_user_func($info['callback'], &$ref);
			} else {
				call_user_func_array($info['callback'], &$info['data']);
			}
		}
		return $n;
	}

	/**
	 * \brief Send an error to parent.
	 * \internal
	 */
	public function Error() {
		$n = func_get_args();
		return $this->sendcmd(self::CMD_ERROR, $n);
	}

	/**
	 * \brief Send an exception report to parent
	 * \param $e Exception
	 * \internal
	 */
	public function Exception($e) {
		Logger::log(Logger::LOG_ERR, 'Got error: '.$e);
		$this->killSelf();
		$this->ping();
	}

	/**
	 * \brief Run a daemon until there's nothing to run
	 * \param $daemon Daemon to run
	 */
	public function run(&$daemon) {
		$cmd = $this->readcmd();
		while($cmd[0] != self::CMD_NOOP) {
			$this->handlecmd($cmd, $daemon);
			$cmd = $this->readbuf();
			if (is_null($cmd)) break;
		}
	}
}


