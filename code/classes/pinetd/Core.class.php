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
 * \file Core.class.php
 * \brief Contains core code for pinetd
 */


namespace pinetd;

/**
 * \class Core
 * \brief Core code for pinetd
 */
class Core {
	/**
	 * \brief Contains SimpleXML object for current config
	 *
	 * This is the configuration file, as a SimpleXML object.
	 */
	private $config;

	/**
	 * \brief List of currently running daemons
	 *
	 * Currently running daemons, in a pid-indexed array
	 */
	private $daemons;
	/**
	 * \brief List of active sockets
	 *
	 * This list contains all active sockets and function to be called if something
	 * happens on this socket.
	 */
	private $fdlist = array();

	public function __construct() {
		$this->config = ConfigManager::invoke();
		$this->daemons = array();
		pcntl_signal(SIGTERM, array(&$this, 'sighandler'), false);
		pcntl_signal(SIGINT, array(&$this, 'sighandler'), false);
		pcntl_signal(SIGCHLD, SIG_DFL, false);
	}

	/**
	 * \brief load a certificate for a given certificate name
	 * \param $SSL string Name of the SSL certificate to load
	 * \return array SSL descriptor
	 */
	public function loadCertificate($SSL) {
		foreach($this->config->SSL->Certificate as $node) {
			if ($node['name'] != $SSL) continue;
			$options = array();
			foreach($node->Option as $opt) {
				if ($opt['Disabled']) continue;
				$val = (string)$opt['Value'];
				if ($val === 'true') $val = true;
				if ($val === 'false') $val = false;
				$var = (string)$opt['name'];
				switch($var) {
					case 'cafile':
					case 'local_cert':
						$val = PINETD_ROOT . '/ssl/' . $val;
						break;
				}
				$options[$var] = $val;
			}
			return $options;
		}
		return null;
	}

	public function _ChildIPC_loadCertificate(&$daemon, $SSL) {
		return $this->loadCertificate($SSL);
	}

	public function registerSocketWait($socket, $callback, &$data) {
		$this->fdlist[$socket] = array('type'=>'callback', 'fd'=>$socket, 'callback'=>$callback, 'data'=>&$data);
	}

	public function removeSocket($fd) {
		unset($this->fdlist[$fd]);
	}

	public function sighandler($signal) {
		switch($signal) {
			case SIGTERM:
			case SIGINT:
				Logger::log(Logger::LOG_INFO, 'Ending signal received, killing children...');
				foreach(array_keys($this->daemons) as $port) {
					$this->killDaemon($port, 800);
				}
				$exp = time() + 12;
				while(count($this->daemons) > 0) {
					$this->checkRunning();
					$this->receiveStopped();
					$this->childrenStatus();
					$this->readIPCs(200000);
					foreach($this->daemons as $id=>$dat) {
						if ($dat['status'] == 'Z') unset($this->daemons[$id]);
					}
					if ($exp <= time()) {
						Logger::log(Logger::LOG_WARN, 'Not all processes finished after 12 seconds');
						break;
					}
				}
				Logger::log(Logger::LOG_INFO, 'Good bye!');
				exit;
			#
		}
	}

	private function loadProcessDaemon($port, $node) {
		// determine HERE if we should fork...
		$daemon = &$this->daemons[$port];
		$good_keys = array('Daemon'=>1, 'SSL' => 1, 'Port' => 1, 'Service' => 1);
		foreach(array_keys($daemon) as $key) {
			if (!isset($good_keys[$key])) unset($daemon[$key]);
		}
		if (!$daemon['Service']) $daemon['Service'] = 'Process';
		$class = 'Daemon\\'.$daemon['Daemon'].'\\'.$daemon['Service'];
		if ((isset($this->config->Global->Security->Fork)) && PINETD_CAN_FORK) {
			// prepare an IPC
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
			if (is_array($pair)) {
				$pid = pcntl_fork();
				if ($pid > 0) {
					SQL::parentForked();
					// parent, record infos about this child
					$this->daemons[$port]['pid'] = $pid;
					$this->daemons[$port]['socket'] = $pair[0];
					$this->daemons[$port]['IPC'] = new IPC($pair[0], false, $this);
					$this->daemons[$port]['status'] = 'R'; // running
					$this->fdlist[$pair[0]] = array('type'=>'daemon', 'port'=>$port,'fd'=>$pair[0]);
					fclose($pair[1]);
					return true;
				} elseif ($pid == 0) {
					SQL::forked();
					fclose($pair[0]);
					pcntl_signal(SIGTERM, SIG_DFL, false);
					pcntl_signal(SIGINT, SIG_IGN, false); // fix against Ctrl+C
					pcntl_signal(SIGCHLD, SIG_DFL, false);
					// cleanup
					foreach($this->fdlist as $dat) fclose($dat['fd']);
					$this->fdlist = array();
					$IPC = new IPC($pair[1], true, $this);
					$IPC->ping();
					Logger::setIPC($IPC);
					try {
						$daemon = new $class($port, $this->daemons[$port], $IPC, $node);
						$IPC->setParent($daemon);
						$daemon->mainLoop();
					} catch(Exception $e) {
						$IPC->Exception($e);
						exit;
					}
					$IPC->Error('Unexpected end of program!', 60);
					exit;
				}
				fclose($pair[0]);
				fclose($pair[1]);
			}
			// if an error occured here, we fallback to no-fork method
		}
		// invoke the process in local scope
		try {
			$this->daemons[$port]['daemon'] = new $class($port, $this->daemons[$port], $this, $node);
			$this->daemons[$port]['status'] = 'I'; // Invoked (nofork)
		} catch(Exception $e) {
			$this->daemons[$port]['status'] = 'Z';
			$this->daemons[$port]['deadline'] = time() + 60;
			Logger::log(Logger::LOG_ERR, 'From process '.$port.': '.$e->getMessage());
		}
	}

	private function loadTCPDaemon($port, $node) {
		// determine HERE if we should fork...
		$daemon = &$this->daemons[$port];
		$good_keys = array('Daemon'=>1, 'SSL' => 1, 'Port' => 1, 'Service' => 1);
		foreach(array_keys($daemon) as $key) {
			if (!isset($good_keys[$key])) unset($daemon[$key]);
		}
		if (!$daemon['Service']) $daemon['Service'] = 'Base';
		$class = 'Daemon\\'.$daemon['Daemon'].'\\'.$daemon['Service'];
		if ((isset($this->config->Global->Security->Fork)) && PINETD_CAN_FORK) {
			// prepare an IPC
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
			if (is_array($pair)) {
				$pid = pcntl_fork();
				if ($pid > 0) {
					SQL::parentForked();
					// parent, record infos about this child
					$this->daemons[$port]['pid'] = $pid;
					$this->daemons[$port]['socket'] = $pair[0];
					$this->daemons[$port]['IPC'] = new IPC($pair[0], false, $this);
					$this->daemons[$port]['status'] = 'R'; // running
					$this->fdlist[$pair[0]] = array('type'=>'daemon', 'port'=>$port,'fd'=>$pair[0]);
					fclose($pair[1]);
					return true;
				} elseif ($pid == 0) {
					SQL::forked();
					fclose($pair[0]);
					pcntl_signal(SIGTERM, SIG_DFL, false);
					pcntl_signal(SIGINT, SIG_IGN, false); // fix against Ctrl+C
					pcntl_signal(SIGCHLD, SIG_DFL, false);
					// cleanup
					foreach($this->fdlist as $dat) fclose($dat['fd']);
					$this->fdlist = array();
					$IPC = new IPC($pair[1], true, $this);
					$IPC->ping();
					Logger::setIPC($IPC);
					try {
						$daemon = new $class($port, $this->daemons[$port], $IPC, $node);
						$IPC->setParent($daemon);
						$daemon->mainLoop();
					} catch(Exception $e) {
						$IPC->Exception($e);
						exit;
					}
					$IPC->Error('Unexpected end of program!', 60);
					exit;
				}
				fclose($pair[0]);
				fclose($pair[1]);
			}
			// if an error occured here, we fallback to no-fork method
		}
		// invoke the process in local scope
		try {
			$this->daemons[$port]['daemon'] = new $class($port, $this->daemons[$port], $this, $node);
			$this->daemons[$port]['status'] = 'I'; // Invoked (nofork)
		} catch(Exception $e) {
			$this->daemons[$port]['status'] = 'Z';
			$this->daemons[$port]['deadline'] = time() + 60;
			Logger::log(Logger::LOG_ERR, 'From daemon on port '.$port.': '.$e->getMessage());
		}
	}

	private function loadUDPDaemon($port, $node) {
		// determine HERE if we should fork...
		$daemon = &$this->daemons[$port];
		$good_keys = array('Daemon'=>1, 'SSL' => 1, 'Port' => 1, 'Service' => 1);
		foreach(array_keys($daemon) as $key) {
			if (!isset($good_keys[$key])) unset($daemon[$key]);
		}
		if (!$daemon['Service']) $daemon['Service'] = 'Base';
		$class = 'Daemon\\'.$daemon['Daemon'].'\\'.$daemon['Service'];
		if ((isset($this->config->Global->Security->Fork)) && PINETD_CAN_FORK) {
			// prepare an IPC
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
			if (is_array($pair)) {
				$pid = pcntl_fork();
				if ($pid > 0) {
					SQL::parentForked();
					// parent, record infos about this child
					$this->daemons[$port]['pid'] = $pid;
					$this->daemons[$port]['socket'] = $pair[0];
					$this->daemons[$port]['IPC'] = new IPC($pair[0], false, $this);
					$this->daemons[$port]['status'] = 'R'; // running
					$this->fdlist[$pair[0]] = array('type'=>'daemon', 'port'=>$port,'fd'=>$pair[0]);
					fclose($pair[1]);
					return true;
				} elseif ($pid == 0) {
					SQL::forked();
					fclose($pair[0]);
					pcntl_signal(SIGTERM, SIG_DFL, false);
					pcntl_signal(SIGINT, SIG_IGN, false); // fix against Ctrl+C
					pcntl_signal(SIGCHLD, SIG_DFL, false);
					// cleanup
					foreach($this->fdlist as $dat) fclose($dat['fd']);
					$this->fdlist = array();
					$IPC = new IPC($pair[1], true, $this);
					$IPC->ping();
					Logger::setIPC($IPC);
					try {
						$daemon = new $class($port, $this->daemons[$port], $IPC, $node);
						$IPC->setParent($daemon);
						$daemon->mainLoop();
					} catch(Exception $e) {
						$IPC->Exception($e);
						exit;
					}
					$IPC->Error('Unexpected end of program!', 60);
					exit;
				}
				fclose($pair[0]);
				fclose($pair[1]);
			}
			// if an error occured here, we fallback to no-fork method
		}
		// invoke the process in local scope
		try {
			$this->daemons[$port]['daemon'] = new $class($port, $this->daemons[$port], $this, $node);
			$this->daemons[$port]['status'] = 'I'; // Invoked (nofork)
		} catch(Exception $e) {
			$this->daemons[$port]['status'] = 'Z';
			$this->daemons[$port]['deadline'] = time() + 60;
			Logger::log(Logger::LOG_ERR, 'From daemon on port '.$port.': '.$e->getMessage());
		}
	}

	function startMissing() {
		$offset = (int)$this->config->Processes['PortOffset'];
		foreach($this->config->Processes->children() as $Type => $Entry) {
			$data = array(
				'Daemon' => (string)$Entry['Daemon'],
				'Service' => (string)$Entry['Service'],
				'SSL' => (string)$Entry['SSL'],
			);
			if (isset($Entry['Port'])) {
				$data['Port'] = (int)$Entry['Port'] + $offset;
			} else {
				$data['Port'] = $data['Daemon'] . '\\' . $data['Service'];
			}
			if (isset($this->daemons[$data['Port']]))
				continue; // no care
			$this->daemons[$data['Port']] = $data;
			$startfunc = 'load'.$Type.'Daemon';
			$this->$startfunc($data['Port'], $Entry);
		}
	}

	function checkRunning() {
		foreach($this->daemons as $port => $data) {
			// do something
		}
	}

	public function Error($errstr) {
		throw new Exception($errstr);
	}

	public function _ChildIPC_killSelf(&$daemon) {
		// mark it "to be killed"
		Logger::log(Logger::LOG_DEBUG, 'pinetd\\Core\\_ChildIPC_killSelf() called for child on port #'.$daemon['Port']);
		if (
				($daemon['status'] != 'R') &&
				($daemon['status'] != 'T')
				)
			return;
		$daemon['IPC']->stop();
		if (!isset($daemon['kill']))
			$daemon['kill'] = time() + 5;
		$daemon['status'] = 'K';
	}

	private function killDaemon($port, $timeout) {
		// kill it!
		if (!isset($this->daemons[$port])) throw new Exception('Unknown port '.$port);
		if ($this->daemons[$port]['status'] == 'I') {
			// not forked
			$this->daemons[$port]['daemon']->shutdown();
			$this->daemons[$port]['status'] = 'Z';
			return;
		}
		if (
				($this->daemons[$port]['status'] != 'R') && 
				($this->daemons[$port]['status'] != 'K') &&
				($this->daemons[$port]['status'] != 'T')
				)
			return;
		if (posix_kill($this->daemons[$port]['pid'], 0)) {
			// still running
			$this->daemons[$port]['IPC']->stop();
			if (!isset($this->daemons[$port]['kill']))
				$this->daemons[$port]['kill'] = time() + 5;
			$this->daemons[$port]['status'] = 'K'; // kill
		} else {
			$this->daemons[$port]['status'] = 'Z'; // zombie
			@fclose($this->daemons[$port]['socket']); // make sure this is closed
			unset($this->fdlist[$this->daemons[$port]['socket']]);
		}
		if (!isset($this->daemons[$port]['deadline']))
			$this->daemons[$port]['deadline'] = time() + $timeout;
	}

	function IPCDied($fd) {
		if (!isset($this->fdlist[$fd])) return; // can't do anything about this
		switch($this->fdlist[$fd]['type']) {
			case 'daemon':
				$port = $this->fdlist[$fd]['port'];
				if ($this->daemons[$port]['status'] == 'R')
				Logger::log(Logger::LOG_DEBUG, 'IPC died on '.$port);
				fclose($fd);
				unset($this->fdlist[$fd]);
				$this->killDaemon($port, 10);
				break;
		}
	}

	private function receiveStopped() {
		if (!PINETD_CAN_FORK) return;
		if (count($this->daemons) == 0) return; // nothing to do
		$res = pcntl_wait($status, WNOHANG);
		if ($res == -1) return; // something bad happened
		if ($res == 0) return; // no process terminated
		// search what ended
		$ended = null;
		foreach($this->daemons as $port => $dat) {
			if ($dat['pid'] == $res) {
				$ended = $port;
				break;
			}
		}
		if (is_null($ended)) return; // we do not know what ended
		if (pcntl_wifexited($status)) {
			$code = pcntl_wexitstatus($status);
			Logger::log(Logger::LOG_INFO, 'Child with pid #'.$res.' on ['.$port.'] exited');
			$this->killDaemon($port, 10);
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
			Logger::log(Logger::LOG_INFO, 'Child with pid #'.$res.' on port '.$port.' died due to signal '.$signal);
			$this->killDaemon($port, 10);
		}
	}

	function readIPCs($timeout) {
		// build $r
		$r = array();
		foreach($this->fdlist as $dat) $r[] = $dat['fd'];
		$res = @stream_select($r, $w = null, $e = null, 0, $timeout);
		if (($res == 0) && (count($r) > 0)) $res = count($r);
		if ($res > 0) {
			foreach($r as $fd) {
				switch($this->fdlist[$fd]['type']) {
					case 'daemon':
						$this->daemons[$this->fdlist[$fd]['port']]['IPC']->run($this->daemons[$this->fdlist[$fd]['port']]);
						break;
					case 'callback':
						$info = &$this->fdlist[$fd];
						call_user_func_array($info['callback'], $info['data']);
						break;
				}
			}
		}
	}

	function childrenStatus() {
		$now = time();
		foreach($this->daemons as $port => &$data) {
			switch($data['status']) {
				case 'R': // running (forked)
				case 'I': // invoked (not forked)
					break;
				case 'K': // to kill
					if ($data['kill'] <= $now) {
						posix_kill($data['pid'], SIGTERM); // die!
						$data['status'] = 'T';
						$data['kill'] = time()+2;
					}
					break;
				case 'T': // terminating
					if ($data['kill'] <= $now) {
						posix_kill($data['pid'], SIGKILL); // DIE!!
						$data['status'] = 'Z';
						break;
					}
				case 'Z': // zombie
					if (!isset($data['deadline'])) {
						unset($this->daemons[$port]);
						break;
					}
					if ($data['deadline'] > $now) break;

					// Restart this daemon
					$offset = (int)$this->config->Processes['PortOffset'];
					foreach($this->config->Processes->children() as $Type => $Entry) {
						if (isset($Entry['Port'])) {
							$tmpport = (int)$Entry['Port'] + $offset;
						} else {
							$tmpport = (string)$Entry['Daemon'] . '\\' . (string)$Entry['Service'];
						}
						if ($tmpport != $port) continue; // we don't want to start this one

						$startfunc = 'load'.$Type.'Daemon';
						$this->$startfunc($port, $Entry);
						break; // ok, finished
					}
					break;
				default:
					echo 'UNKNOWN STATUS '.$data['status']."\n";
				#
			}
		}
	}
	
	function mainLoop() {
		while(1) {
			$this->checkRunning();
			$this->startMissing();
			$this->receiveStopped(); // waitpid
			$this->childrenStatus();
			$this->readIPCs(200000);
		}
	}
}


