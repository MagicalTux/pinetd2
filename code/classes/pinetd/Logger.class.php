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
 * \file Logger.class.php
 * \brief Logger class, a fork-aware logger
 * \namespace pinetd
 */

namespace pinetd;

/**
 * \class Logger
 * \brief Logger class, for easy logging
 *
 * This class main function, Logger::log, is used to add a message to the main log.
 */
class Logger {
	/**
	 * \brief $self as we are a singleton
	 */
	private static $self = null;
	/**
	 * \brief Reference to the IPC class
	 */
	private $IPC = null;

	const LOG_DEBUG= 'DEBUG'; /*!< Signals a DEBUG log message */
	const LOG_INFO = 'INFO'; /*!< A simple log message, with no real importance */
	const LOG_WARN = 'WARN'; /*!< Something went bad, but it's not fatal. Should be fixed if possible */
	const LOG_ERR  = 'ERR'; /*!< A fatal error occured, and the daemon had to stop its current operation */
	const LOG_CRIT = 'CRIT'; /*!< A fatal error occured, causing the daemon to stop */

	/**
	 * \brief Invokes Logger singleton, and returns it
	 * \return a Logger (unique) instance
	 */
	private function invoke() {
		if (is_null(self::$self)) self::$self = new self();
		return self::$self;
	}

	/**
	 * \brief Log data to the main log
	 * \param $level one of Logger::LOG_DEBUG Logger;;LOG_INFO Logger::WARN Logger::LOG_ERR Logger::LOG_CRIT
	 * \param $msg string to log
	 * \return bool Logging successful?
	 */
	public static function log($level, $msg) {
		$logger = self::invoke();
		return $logger->do_log(getmypid(), time(), $level, $msg);
	}

	/**
	 * \brief Log with full attributes definition
	 * \param $pid int Pid originating the log
	 * \param $stamp int Timestamp associated with log entry
	 * \param $level See $level parameter of Logger::log
	 * \param $msg string to log
	 * \return bool Logging successful?
	 *
	 * This function is identiqual to Logger::log() but provides more control over
	 * logged data. This function SHOULD NOT be used directly.
	 */
	public static function log_full($pid, $stamp, $level, $msg) {
		$logger = self::invoke();
		return $logger->do_log($pid, $stamp, $level, $msg);
	}

	/**
	 * \brief real function behind logging
	 * \internal
	 * \param $pid int Pid originating the log
	 * \param $stamp int Timestamp associated with log entry
	 * \param $level See $level parameter of Logger::log
	 * \param $msg string to log
	 * \return bool Logging successful?
	 */
	public function do_log($pid, $stamp, $level, $msg) {
		if (!is_null($this->IPC)) {
			return $this->IPC->log($pid, $stamp, $level, $msg);
		}

		// TODO: allow to override this in config
		$logdir = PINETD_ROOT . '/logs';
		if (!is_dir($logdir)) mkdir($logdir);
		$fp = fopen($logdir . '/system_'.date('Y-m').'.log', 'a');
		if (!$fp) {
			echo '['.date('Y-m-d H:i:s').':'.$pid.'] '.$level.': '.$msg."\n";
		} else {
			fwrite($fp, '['.date('Y-m-d H:i:s').':'.$pid.'] '.$level.': '.$msg."\n");
			fclose($fp);
		}
	}

	/**
	 * \brief Define IPC used by logger
	 * \param $IPC New IPC class (after a fork for example)
	 */
	public static function setIPC(&$IPC) {
		$logger = self::invoke();
		return $logger->real_setIPC($IPC);
	}

	/**
	 * \brief Define IPC used by logger, internal use only
	 * \param $IPC New IPC class (after a fork for example)
	 * \internal
	 */
	public function real_setIPC(&$IPC) {
		$this->IPC = &$IPC;
	}
}

