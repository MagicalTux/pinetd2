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

use \Exception;

/**
 * \class IPC
 * \brief IPC (Inter-Process Communications) class, used by parents and children
 */
class IPC {
	private $pipe; /*!< Pipe to my child/parent */
	private $ischld; /*!< Am I a child?? */
	private $buf = ''; /*!< Input buffer (reads are non blocking) */
	private $parent = NULL; /*!< My "parent" class, where functions are called */
	private $parentipc = NULL; /*!< My "parent IPC" class, for some specific stuff */
	private $fds = array(); /*!< List of fds to listens, and callbacks */
	private $ports = array(); /*!< Port routing table */

	const CMD_PING = 'PING'; /*!< PING command, should receive RES_PING from peer */
	const CMD_NOOP = 'NOOP'; /*!< NOOP command, just to NOOP (NB: this is virtual, should never be sent) */
	const CMD_STOP = 'STOP'; /*!< STOP a child, only parent can send this */
	const CMD_LOG = 'LOG'; /*!< LOG data to the log subsystem */
	const CMD_ERROR = 'ERR'; /*!< Some kind of error occured, for example the system died */
	const CMD_CALL = 'CALL'; /*!< CALL a peer function. This will call a function on peer's class */
	const CMD_NEWPORT = 'NEWPORT'; /*!< Create a new port, and announce to parent (if any) */
	const CMD_CALLPORT = 'CALLPORT'; /*!< Call a function on a port */

	const RES_PING = 'PONG'; /*!< PONG, the reply to ping */
	const RES_CALL = 'RCALL'; /*!< Reply to a CMD_CALL, containing the result of the called function */
	const RES_CALL_EXCEPT = 'RCALLX'; /*!< A CMD_CALL resulted in an exception to be thrown */
	const RES_NEWPORT = 'RNEWPORT'; /*!< A CMD_NEWPORT reply */
	const RES_CALLPORT = 'RCALLPORT'; /*!< A CMD_CALLPORT reply */
	const RES_CALLPORT_EXCEPT = 'RCALLPORTX'; /*!< Transport for exception when calling ports */

	/**
	 * \brief IPC constructor
	 * \param $pipe Pipe to remote parent/child
	 * \param $ischld bool Am I a child?
	 * \param $parent If not a child, put $this here (reference to parent when parent)
	 */
	function __construct($pipe, $ischld, &$parent, &$parentipc) {
		$this->pipe = $pipe;
		$this->ischld = $ischld;
		if (!$this->ischld) { // parent
			stream_set_blocking($this->pipe, false); // parent should not hang
			$this->parent = &$parent;
			$this->parentipc = &$parentipc;
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
	public function registerSocketWait($fd, $callback, &$data) {
		if (!is_array($data)) throw new Exception('No data defined :(');
		$this->fds[(int)$fd] = array('fd'=>$fd, 'last_activity' => time(), 'callback' => $callback, 'data' => &$data);
	}

	public function setTimeOut($fd, $timeout, $callback, &$data) {
		if (!is_array($data)) throw new Exception('No data defined!');
		$this->fds[(int)$fd]['timeout'] = $timeout;
		$this->fds[(int)$fd]['timeout_callback'] = $callback;
		$this->fds[(int)$fd]['timeout_data'] = &$data;
	}

	/**
	 * \brief Get callback data by socket
	 * \param $fd The fd to be checked
	 * \return mixed data or null if fd not known
	 */
	public function getSocketInfo($fd) {
		return $this->fds[(int)$fd]['data'];
	}

	/**
	 * \brief Stop listening on a stream
	 * \param $fd to stop listening on
	 */
	public function removeSocket($fd) {
		unset($this->fds[(int)$fd]);
	}

	/**
	 * \brief Define parent class for this IPC. Works only once
	 * \param $parent The parent class
	 */
	public function setParent(&$parent) {
		if (is_null($this->parent)) $this->parent = &$parent;
	}

	/**
	 * \brief Creates a logical IPC port
	 * \param $port_name The port's name
	 * \param $class Object handling calls to this port
	 */
	public function createPort($port_name, &$class) {
		if (!$this->ischld) throw new Exception('This is not possible, man!');
		$this->sendcmd(self::CMD_NEWPORT, $port_name);
		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			$cmd = $this->readcmd();
			// TODO: handle exceptions too?
			if ($cmd[0] != self::RES_NEWPORT) {
				$this->handlecmd($cmd, $foo = null);
			} else {
				$res = $cmd[1];
				break;
			}
		}

		if ($res) {
			$this->ports[$port_name] = array('type' => 'class', 'class' => &$class);
		}

		return $res;
	}

	public function openPort($port) {
		return new IPC_Port($this, $port);
	}

	public function callPort($port_name, $method, array $args) {
		if (!$this->ischld) throw new Exception('This is not possible either, man!');
		$this->sendcmd(self::CMD_CALLPORT, array($port_name, array(), $method, $args));
		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			$cmd = $this->readcmd();
			if ($cmd[0] == self::RES_CALLPORT_EXCEPT) {
				throw $cmd[1];
			} elseif ($cmd[0] != self::RES_CALLPORT) {
				$this->handlecmd($cmd, $foo = null);
			} else {
				return $cmd[1][2];
			}
		}
	}

	/**
	 * \brief Send a command to peer
	 * \param $cmd Command name
	 * \param ... Parameters
	 * \internal
	 */
	public function sendcmd() {
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

	protected function internalCallPort($call) {
		$port = $call[0];
		$method = $call[2];

		$class = &$this->ports[$call[0]]['class']; // at this point, ports are only class type
		if ($class instanceof IPC) {
			$class->sendcmd(IPC::CMD_CALLPORT, $call);
		} else {
			$method = $call[2];
			try {
				$res = call_user_func_array(array($class, $method), $call[3]);
			} catch(Exception $e) {
				$exception = array(
					$call[0],
					$call[1],
					$e,
				);
				$this->routePortReply($exception, true);
				return;
			}

			$result = array(
				$call[0],
				$call[1],
				$res,
			);
			$this->routePortReply($result);
		}
	}

	protected function routePortReply($reply, $exception = false) {
		$target = array_pop($reply[1]);
		if ($target == '@parent') {
			$this->sendcmd(self::RES_CALLPORT, $reply);
		} else {
			var_dump($target, $reply);
			exit;
		}
	}

	/**
	 * \brief Handle a command request
	 * \param $cmd array The command to handle
	 * \param $daemon Which child caused this call (if child), for CMD_CALL
	 * \internal
	 */
	private function handlecmd($cmd, &$daemon, $fd = -1) {
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
				call_user_func_array(array('pinetd\\Logger', 'log_full'), $cmd[1]);
				break;
			case self::CMD_NEWPORT:
				$port = $cmd[1];
				if (isset($this->ports[$port])) {
					// return false!
					$this->sendcmd(self::RES_NEWPORT, false);
					break;
				}
				$res = $this->parentipc->createPort($port, $this);
				if ($res) {
					$this->ports[$port] = array('type' => 'child');
				}
				$this->sendcmd(self::RES_NEWPORT, $res);
				break;
			case self::CMD_CALLPORT:
				$port = $cmd[1][0];
				// do we have this port in our routing table?
				if (isset($this->ports[$port])) {
					$cmd[1][1][] = '@parent';
					$this->internalCallPort($cmd[1]);
					break;
				}
				// if not, follow to parent
				$cmd[1][1][] = (int)$fd;
				if ($this->parentipc instanceof IPC) {
					$this->parentipc->sendcmd(self::CMD_CALLPORT, $cmd[1]);
				} else {
					$this->parentipc->callPort($cmd[1]);
				}
				break;
			case self::RES_CALLPORT:
				$port = $cmd[1][0];
				$next = array_pop($cmd[1][1]);
				if ($next == '@parent') {
					if ($this->parentipc instanceof IPC) {
						$this->parentipc->sendcmd(self::CALLPORT, $cmd[1]);
					} else {
						$this->parentipc->routePortReply($cmd[1]);
					}
					break;
				}
				if (!isset($this->fds[$next])) break; // child has died while doing an IPC call?
				$info = &$this->fds[$next];
				if ( (is_array($info['callback'])) && ($info['callback'][1] == 'run') && ($info['callback'][0] instanceof IPC)) {
					$class = $info['callback'][0];
					$class->sendcmd(self::RES_CALLPORT, $cmd[1]);
				} else {
					var_dump('huh?');
				}
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
		$now = time();
		foreach($this->fds as &$c) {
			if ($c['last_activity'] < ($now - $c['timeout'])) {
				$c['last_activity'] = $now;
				call_user_func_array($c['callback_timeout'], &$c['callback_data']);
			}
			$r[] = $c['fd'];
		}

		$n = @stream_select($r, $w=null, $e=null, 0, $timeout);
		if (($n==0) && (count($r)>0)) $n = count($r); // stream_select returns weird values sometimes Oo
		if ($n<=0) return $n;
		foreach($r as $fd) {
			$info = &$this->fds[$fd];
			$info['last_activity'] = $now;
			// somewhat dirty (but not so dirty) workaround for a weird PHP 5.3 behaviour
			if ( (is_array($info['callback'])) && ($info['callback'][1] == 'run')) {
				// This function expects one parameter, and by ref
				$ref = &$info['data'][0];
				call_user_func($info['callback'], &$ref, $fd);
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
	public function run(&$daemon, $fd) {
		$cmd = $this->readcmd();
		while($cmd[0] != self::CMD_NOOP) {
			$this->handlecmd($cmd, $daemon, $fd);
			$cmd = $this->readbuf();
			if (is_null($cmd)) break;
		}
	}
}

class IPC_Port {
	private $IPC;
	private $port;

	public function __construct(&$IPC, $port) {
		$this->IPC = &$IPC;
		$this->port = $port;
	}

	public function __call($method, $args) {
		return $this->IPC->callPort($this->port, $method, $args);
	}
}

