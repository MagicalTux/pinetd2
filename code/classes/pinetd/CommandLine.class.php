<?php

namespace pinetd;

class CommandLine {
	public static function handle() {
		// handle command line
		if (!isset($_SERVER['argc'])) die("Problem with PHP! :(\r\n");

		if ($_SERVER['argc'] == 1) {
			self::usage();
			exit(1);
		}

		$process = new MasterSocket();

		switch($_SERVER['argv'][1]) {
			case 'start':
				if ($process->connected()) {
					echo 'pinetd is already running with pid '.$process->getPid()."\r\n";
					exit(1);
				}
				return self::start();
			case 'stop':
				if (!$process->connected()) {
					echo 'pinetd is not running!'."\r\n";
					exit(1);
				}
				echo 'Stopping pinetd...'."\r\n";
				$process->stop(array(__CLASS__, 'stopStatus'));

				exit(0);
			case 'restart':
				if (!$process->connected()) {
					echo 'pinetd is not running, trying to start...'."\r\n";
				} else {
					echo 'Stopping pinetd...'."\r\n";
					$process->stop(array(__CLASS__, 'stopStatus'));
				}
				return self::start();
			case 'status':
				if (!$process->connected()) {
					echo 'pinetd is not running!'."\r\n";
					exit(1);
				}
				$version = $process->getVersion();
				$pid = $process->getPid();
				$daemons = $process->daemons();
				echo 'pinetd '.$version.' at pid '.$pid."\r\n";
				foreach($daemons as $id => $daemon) self::showDaemon($daemon);
				exit(0);
			default:
				self::usage();
				exit(1);
		}
		exit;
	}

	public static function stopStatus($info, $daemons) {
		if ($info['type'] == 'ack') {
			switch($info['ack']) {
				case 'stop': echo 'Main Process: closing daemons...'."\r\n"; break;
				case 'stop_finished': echo 'Main Process: All daemons closed!'."\r\n"; break;
			}
			return;
		}
		if ($info['type'] == 'finished') {
			$daemon = $daemons[$info['finished']];
			echo 'Stopped daemon: ';
			self::showDaemon($daemon);
			return;
		}
		var_dump($info);
	}

	public static function showDaemon($daemon) {
		echo $daemon['Type'].' daemon';
		if (isset($daemon['Ip'])) {
			echo ' on '.$daemon['Ip'].':'.$daemon['Port'];
		} elseif (isset($daemon['Port'])) {
			echo ' on port '.$daemon['Port'];
		}
		echo ' running '.$daemon['Daemon'].'/'.$daemon['Service'];
		echo "\r\n";
	}

	public static function usage() {
		echo 'Usage: '.$_SERVER['argv'][0].' start|stop|status|restart'."\r\n";
	}

	public static function start() {
		echo 'Starting pinetd '.PINETD_VERSION.' ...';
		$config = ConfigManager::invoke();
		if ((isset($config->Global->Security->Fork)) && PINETD_CAN_FORK) {
			$pid = pcntl_fork();
			if ($pid > 0) {
				echo "$pid\n";
				exit;
			}
			posix_setsid();
		}
		return;
	}
}

