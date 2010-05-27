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
	private $parentipc = NULL; /*!< My "parent IPC" class, for some specific stuff */
	private $fds = array(); /*!< List of fds to listens, and callbacks */
	private $ports = array(); /*!< Port routing table */
	private $bcast_listen = array(); /*!< list of broadcast listeners */
	private $parent_ipc = NULL;

	const CMD_PING = 'PING'; /*!< PING command, should receive RES_PING from peer */
	const CMD_NOOP = 'NOOP'; /*!< NOOP command, just to NOOP (NB: this is virtual, should never be sent) */
	const CMD_STOP = 'STOP'; /*!< STOP a child, only parent can send this */
	const CMD_LOG = 'LOG'; /*!< LOG data to the log subsystem */
	const CMD_ERROR = 'ERR'; /*!< Some kind of error occured, for example the system died */
	const CMD_CALL = 'CALL'; /*!< CALL a peer function. This will call a function on peer's class */
	const CMD_NEWPORT = 'NEWPORT'; /*!< Create a new port, and announce to parent (if any) */
	const CMD_CALLPORT = 'CALLPORT'; /*!< Call a function on a port */
	const CMD_BCAST = 'BCAST'; /*!< broadcast event */

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
		return $this->doCall($func, $args, $this->ischld);
	}

	function doCall($func, $args, $waitanswer = true) {
		array_unshift($args, $func);
		$this->sendcmd(self::CMD_CALL, $args);
		if (!$waitanswer) return null;
		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			pcntl_signal_dispatch();
			$cmd = $this->readcmd();
			if ($cmd[0] == self::RES_CALL_EXCEPT) {
				throw new \Exception($cmd[1]); // throw exception again in this process
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
		if ((is_array($callback)) && ($callback[0] instanceof self) && ($callback[0] != $this)) {
			$callback[0]->parent_ipc = $this;
		}
		if (!is_array($data)) throw new \Exception('No data defined :(');
		$this->fds[(int)$fd] = array('fd'=>$fd, 'last_activity' => time(), 'callback' => $callback, 'data' => &$data);
	}

	public function setTimeOut($fd, $timeout, $callback, &$data) {
		if (!is_array($data)) throw new \Exception('No data defined!');
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
		if (!$this->ischld) throw new \Exception('This is not possible, man!');
		$this->sendcmd(self::CMD_NEWPORT, $port_name);
		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			pcntl_signal_dispatch();
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

	/**
	 * \brief Call a port method
	 * \param $port_name Name of the port to call
	 * \param $method Port method to call
	 * \param $args Arguements to be passed to this port
	 * \param $wait Shall we wait for reply [default=true]
	 * \return Port call result, or NULL if \a $wait is false
	 *
	 * This method will call an IPC port's method. See createPort
	 * for more details.
	 */
	public function callPort($port_name, $method, array $args, $wait = true) {
		if (!$this->ischld) throw new \Exception('This is not possible either, man!');
		$this->sendcmd(self::CMD_CALLPORT, array($port_name, array(), $method, $args));
		if (!$wait) return NULL;

		while(!feof($this->pipe)) {
			@stream_select($r = array($this->pipe), $w = null, $e = null, 1); // wait
			pcntl_signal_dispatch();
			$cmd = $this->readcmd();
			if ($cmd[0] == self::RES_CALLPORT_EXCEPT) {
				throw new \Exception($cmd[1][2]);
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
		if (feof($this->pipe)) {
			if ($this->ischld) exit;
			return NULL;
		}
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

	public function listenBroadcast($code, $key, $callback) {
		$this->bcast_listen[$code][$key] = $callback;
	}

	public function unlistenBroadcast($code, $key) {
		unset($this->bcast_listen[$code][$key]);
		if (!$this->bcast_listen[$code]) unset($this->bcast_listen[$code]);
	}

	public function broadcast($code, $data = null, $except = 0) {
		if (isset($this->bcast_listen[$code]))
			foreach($this->bcast_listen[$code] as $key => $callback) call_user_func($callback, $data, $code, $key);

		if ($this->ischld) {
			foreach($this->fds as $id => $info) {
				$class = $info['callback'];
				if (!is_array($class)) continue;
				$class = $class[0];
				if (!($class instanceof self)) continue;
				if ($id == $except) continue;
				if ($class == $this) continue;
				$class->broadcast($code, $data);
			}
			if (!$except) {
				$this->sendcmd(self::CMD_BCAST, array($code, $data));
			}
			return true;
		}
		if (!$except) {
			$this->sendcmd(self::CMD_BCAST, array($code, $data));
		} else if ($this->parent instanceof Core) {
			$this->parent->broadcast($code, $data, (int)$this->pipe);
		} else if (!is_null($this->parent_ipc)) {
			$this->parent_ipc->broadcast($code, $data, (int)$this->pipe);
		}
		return true;
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
				if (method_exists($class, '_asyncPort_'.$method)) {
					$reply = array($call[0], $call[1]);
					$res = call_user_func(array($class, '_asyncPort_'.$method), $call[3], $reply);
					return;
				}
				$res = call_user_func_array(array($class, $method), $call[3]);
			} catch(\Exception $e) {
				$exception = array(
					$call[0],
					$call[1],
					$e->getMessage(),
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

	public function routePortReply($reply, $exception = false) {
		$target = array_pop($reply[1]);
		if ($target == '@parent') {
			$this->sendcmd($exception ? self::RES_CALLPORT_EXCEPT : self::RES_CALLPORT, $reply);
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
			if (!$this->parent) throw new \Exception('Peer got away');
			if ($this->ischld) exit();
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
			case self::CMD_BCAST:
				// send to all children except (int)$fd
				$this->broadcast($cmd[1][0], $cmd[1][1], (int)$fd);
				break;
			case self::CMD_CALL:
				// $cmd[1] = array(function, args)
				$key = ($this->ischld)?'_ParentIPC_':'_ChildIPC_';
				$func = array($this->parent, $key.$cmd[1][0]);
				if (!is_callable($func)) {
					Logger::log(Logger::LOG_ERR, 'Tried to call '.get_class($func[0]).'::'.$func[1].' but failed!');
					if (!$this->ischld) $this->sendcmd(self::RES_CALL, null); // avoid deadlock
					break;
				}
				$cmd[1][0] = &$daemon;
				try {
					$res = call_user_func_array($func, $cmd[1]);
				} catch(\Exception $e) {
					$this->sendcmd(self::RES_CALL_EXCEPT, $e->getMessage());
					break;
				}
				$this->sendcmd(self::RES_CALL, $res);
				break;
			case self::RES_CALL:
				// nothing to care about this?
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
			case self::RES_CALLPORT_EXCEPT:
				$port = $cmd[1][0];
				if (!$cmd[1][1]) break; // this result reached us, but we don't care anymore
				$next = array_pop($cmd[1][1]);
				if ($next == '@parent') {
					if ($this->parentipc instanceof IPC) {
						$this->parentipc->sendcmd($cmd[0], $cmd[1]);
					} else {
						$this->parentipc->routePortReply($cmd[1], $cmd[0] == self::RES_CALLPORT_EXCEPT);
					}
					break;
				}
				if (!isset($this->fds[$next])) break; // child has died while doing an IPC call?
				$info = &$this->fds[$next];
				if ( (is_array($info['callback'])) && ($info['callback'][1] == 'run') && ($info['callback'][0] instanceof IPC)) {
					$class = $info['callback'][0];
					$class->sendcmd($cmd[0], $cmd[1]);
				} else {
					var_dump('huh?');
				}
				break;
			default:
				throw new \Exception('Unknown command '.$cmd[0]);
		}
		return true;
	}

	/**
	 * \brief Pass log messages to parent
	 * \internal
	 */
	public function log() {
		if (!$this->ischld) throw new \Exception('Parent sending logs to child makes no sense');
		$n = func_get_args();
		return $this->sendcmd(self::CMD_LOG, $n);
	}

	/**
	 * /brief Stop attached child
	 */
	public function stop() {
		if ($this->ischld) throw new \Exception('Children can\'t tell their parents to go to sleep');
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
			if ( (isset($c['timeout'])) && ($c['timeout'] > 0)) {
				if ($c['last_activity'] < ($now - $c['timeout'])) {
					$c['last_activity'] = $now;
					call_user_func_array($c['timeout_callback'], &$c['timeout_data']);
				}
			}
			$r[] = $c['fd'];
		}

		$n = @stream_select($r, $w=null, $e=null, 0, $timeout);
		pcntl_signal_dispatch();
		if (($n==0) && (count($r)>0)) $n = count($r); // stream_select returns weird values sometimes Oo
		if ($n<=0) {
			// nothing has happened, let's collect garbage collector cycles
			gc_collect_cycles();
			$this->waitChildren();
			return $n;
		}
		foreach($r as $fd) {
			$info = &$this->fds[(int)$fd];
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
		$this->waitChildren();
		return $n;
	}

	public function waitChildren() {
		if (!PINETD_CAN_FORK) return;
		if (!function_exists('pcntl_wait')) return;
		$res = pcntl_wait($status, WNOHANG);
		if ($res == -1) return; // something bad happened
		if ($res == 0) return; // no process terminated

		if (pcntl_wifstopped($status)) {
			Logger::log(Logger::LOG_INFO, 'Waking up stopped child on pid '.$res);
			posix_kill($res, SIGCONT);
			return;
		}

		// dispatch signal (if possible)
		if (is_callable(array($this->parent, 'childSignaled'))) {
			if (pcntl_wifexited($status)) {
				$code = pcntl_wexitstatus($status);
				return $this->parent->childSignaled($res, $code, NULL);
			}

			if (pcntl_wifsignaled($status)) {
				$signal = pcntl_wtermsig($status);
				$code = pcntl_wexitstatus($status);
				$const = get_defined_constants(true);
				$const = $const['pcntl'];
				foreach($const as $var => $val) {
					if (substr($var, 0, 3) != 'SIG') continue;
					if (substr($var, 0, 4) == 'SIG_') continue;
					if ($val != $signal) continue;
					$signal = $var;
					break;
				}
				$this->parent->childSignaled($res, $code, $signal); // pid, status, signal
			}
		}
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
	public function Exception(\Exception $e) {
		Logger::log(Logger::LOG_ERR, 'Got error: '.$e->getMessage());
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

