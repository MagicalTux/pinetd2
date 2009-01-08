<?php

namespace Daemon\SimpleBNC;

class Server_Thread extends \pinetd\TCP\Client {

	public function welcomeUser() {
		$this->setMsgEnd("\n");
		return true;
	}

	public function sendBanner() {
		$this->server = $this->IPC->openPort('SimpleBNC::Transport');
		var_dump($this->localConfig);
	}

	protected function parseLine($lin) {
		$line   =   rtrim($lin);

		if (strtolower(substr($line, 4)) === 'quit') {
			$this->close();
		}
				
		if (!$this->logged) {
			$this->login($line);
			return true;
		}
	}
	
	private function login($line) {
		$words  =   explode(' ', $line);
		if (!strtolower($words[0]) === 'pass') {
			$this->close();
		}
		$account	=   explode(':', $words[1]);
		
		$account = new Account($account);
		
		if (!$account->logged) {
			$this->close();
		}
		$account->loadConfig();
	}

}
class Account {

	function login(Array $account) {
		return true;
	}
}
