<?php

namespace Daemon\PMaild\MTA;

use pinetd\Logger;
use pinetd\SQL;

class Auth {
	private $login = null;
	private $info = null;
	private $SQL;

	public function __construct($localConfig) {
		$this->SQL = SQL::Factory($localConfig['Storage']);
	}

	public function getLogin() {
		return $this->login;
	}

	public function getInfo() {
		return $this->info;
	}

	public function login($login, $pass, $mode = null) {
		$pos = strrpos($login, '@');
		if ($pos === false) $pos = strrpos($login, '+'); // compatibility with old-style stuff
		if ($pos === false) return false;
		$domain = substr($login, $pos+1);
		$user = substr($login, 0, $pos);
		$info = array(
			'domain' => $domain,
			'user' => $user,
		);

		// load domain
		$DAO_domains = $this->SQL->DAO('domains', 'domainid');
		$domain = $DAO_domains->loadByField(array('domain' => $domain));

		if (!$domain) return false;
		$domain = $domain[0];

		$info['domainid'] = $domain->domainid;

		if(!is_null($mode)) {
			// check if domain has required protocol
			$proto = array_flip(explode(',', $domain->protocol));
			if (!isset($proto[$mode])) {
				Logger::log(Logger::LOG_INFO, strtoupper($mode).' login denied to user '.$login.': '.strtoupper($mode).' disabled');
				return false;
			}
		}
		
		$DAO_accounts = $this->SQL->DAO('z'.$domain->domainid.'_accounts', 'id');
		$account = $DAO_accounts->loadByField(array('user'=>$user));

		if (!$account) return false;
		$account = $account[0];

		if (is_null($account->password)) {
			if (strlen($pass) < 4) return false;
			$account->password = crypt($pass);
			$account->commit();
			Logger::log(Logger::LOG_INFO, 'Recording new password for user '.$login);
		}

		// check password
		if ($account->password[0] == '$') {
			$pass = crypt($pass, $account->password);
		} else {
			switch(strlen($account->password)) {
				case 13: // old-style unix passwords, limited to 8 chars, highly discouraged
					$pass = crypt($pass, $account->password);
					break;
				case 32:
					$pass = md5($pass);
					break;
				case 40:
					$pass = sha1($pass);
					break;
				default:
					return false; // password disabled?
			}
		}
		if ($pass != $pass) return false; // auth failed
		Logger::log(Logger::LOG_DEBUG, get_class($this).': User '.$login.' logged in successfully'.(is_null($mode)?'':' on '.$mode));
		$account->last_login = $this->SQL->now();
		$account->commit(); // will also commit password if set

		$info['account'] = $account;

		$this->info = $info;
		$this->login = $account->user . '@' . $domain->domain;
		return true;
	}
}

