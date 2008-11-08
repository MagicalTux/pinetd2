<?php
namespace Daemon::FTPd_SQL;

class Base extends ::Daemon::FTPd::Base {
	private $sql;

	public function __construct($port, $daemon, &$IPC, $node) {
		parent::__construct($port, $daemon, &$IPC, $node);
		// once __construct runs, we have a localConfig
		$this->sql = ::pinetd::SQL::Factory($this->localConfig['Storage']);
	}

	public function checkAccess($login, $pass, $peer) {
		// check if login/pass is allowed to connect
		
		$this->sql->ping();
		$query = $this->localConfig['SQL']['LoginQuery']['_'];
		$query = sprintf($query, $this->sql->quote_escape($login));
		$res = $this->sql->query($query);
		$res = $this->sql->query($query);
		if (!$res) {
			::pinetd::Logger::log(::pinetd::Logger::LOG_ERR, 'login SQL query: ' . $query);
			::pinetd::Logger::log(::pinetd::Logger::LOG_ERR, 'login SQL query failed: ' . $this->sql->error);
			return false;
		}
		$res = $res->fetch_row();

		if (!$res) return false;

		if ($pass != $res[0]) return false;

		::pinetd::Logger::log(::pinetd::Logger::LOG_INFO, 'User '.$login.' logging in from '.$peer[0].':'.$peer[1].' ('.$peer[2].')');

		$array = array(
			'root' => $res[1], // where should we have access
//			'chdir' => '/', // pre-emptive chdir, relative to the FTP root
//			'suid_user' => 'nobody', // force suid on login
//			'suid_group' => 'nobody', // force suid on login
		);
		if (isset($res[2])) {
			$array['chdir'] = $res[2];
		}
		return $array;
	}
}

