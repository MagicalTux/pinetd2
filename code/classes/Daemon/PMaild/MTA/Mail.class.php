<?php

namespace Daemon\PMaild\MTA;

class Mail {
	protected $peer;
	protected $IPC;
	protected $login = null;
	protected $helo = NULL;
	protected $from = null;
	protected $to = array();
	protected $received = array();
	protected $localConfig;
	protected $txn = null;
	protected $txlog = array();

	private $error; // to err is human

	function __construct($peer, $IPC) {
		$this->peer = $peer;
		$this->IPC = $IPC;
		$this->localConfig = $this->IPC->getLocalConfig();
	}

	static public function header($head, $val) {
		$val = $head.': '.$val;
		return wordwrap($val, 76, "\r\n\t", true)."\r\n";
	}

	protected function err($str) { // I err so I am
		$this->error = $str;
		return false;
	}

	function errorMsg() {
		return $this->error;
	}

	function reset() {
		$this->from = null;
		$this->to = array();
		$this->received = array();
	}

	function setHelo($helo) {
		if (strlen($helo) < 3) return false;
		$this->helo = $helo;
		return true;
	}

	function setLogin($login, $pass) {
		// need to check auth
		$class = relativeclass($this, 'Auth');
		$auth = new $class($this->localConfig);
		if (!$auth->login($login, $pass, 'smtp')) return false;
		$this->received[] = 'SMTP authenticated user logged in; '.base64_encode($auth->getLogin()).'; '.date(DATE_RFC2822);
		$this->login = $auth->getLogin();
		return true;
	}

	function setFrom($from) {
		if (!is_null($this->from)) {
			return $this->err('503 5.5.2 Syntax error: MAIL FROM already given');
		}
		$this->from = $from;
		if (!is_null($this->peer)) $this->received[] = 'from '.$this->helo.' ('.$this->peer[2].' ['.$this->peer[0].']) by '.$this->localConfig['Name']['_'].' (pMaild); '.date(DATE_RFC2822);
		return true;
	}

	function addTarget($mail) {
		if (is_null($this->from)) return $this->err('503 5.5.2 Syntax error: Need MAIL FROM before RCPT TO');
		if (strlen($mail) > 128) return $this->err('503 5.1.1 Mail too long');
		// parse mail
		$pos = strrpos($mail, '@');
		if ($pos === false) return $this->err('503 5.1.3 Where did you see an email without @ ??');
		$class = relativeclass($this, 'MailSolver');
		$mail = $class::solve($mail, $this->localConfig);
		if (!is_array($mail)) {
			if (is_string($mail)) return $this->err($mail);
			return $this->err('403 4.3.0 Unknown error in mail solver subsystem');
		}
		if (isset($this->to[$mail['mail']])) {
			return $this->err('403 4.5.3 Already gave this destination email once');
		}
		if ( (is_null($this->login)) && (!is_null($this->peer)) ) {
			if ($mail['type'] == 'remote') {
				if (!$this->allowRemote) return $this->err('503 5.1.2 Relaying denied');
			} else {
				$class = relativeclass($this, 'DNSBL');
				$bl = $class::check($this->peer, $mail, $this->localConfig);
				if ($bl) return $this->err('550 5.1.8 Your host is blacklisted: '.$bl);
			}
		}
		$this->to[$mail['mail']] = $mail;
		return true;
	}

	public function sendMail($stream = null) {
		// prepare sending a mail
		if (!is_null($this->txn)) throw new Exception('Something wrong happened');
		$txn = array(
			'helo' => $this->helo,
			'peer' => $this->peer,
		);
		$txn['fd'] = fopen('php://temp', 'r+'); // php will write mail in memory if <2M
		foreach(array_reverse($this->received) as $msg) fputs($txn['fd'], self::header('Received', $msg));
		$this->txn = &$txn;
		$txn['parent'] = &$this;
		return $txn;
	}

	public function finishMail() {
		if (is_null($this->txn)) throw new Exception('finishMail() called without email transaction');
		// ok, we got our data in txn, time to spawn a maildelivery class
		$success = 0;
		$total = 0;
		$txn = $this->txn;
		$this->txn = null;
		$this->txlog = array();
		foreach($this->to as $target) {
			$total++;
			$class = relativeclass($this, 'MailTarget');
			$MT = new $class($target, $this->from, $this->localConfig);
			$err = $MT->process($txn);
			if (is_null($err)) {
				$success++;
			}
			$this->txlog[$target['mail']] = $err;
		}
		if ($success != $total) {
			if ($total == 1) return $this->err($err);
			return $this->err('450 4.5.3 One or more failure while processing mail (success rate: '.$success.'/'.$total.' - use TXLG for details)');
		}
		return true;
	}

	public function cancelMail() {
		if (is_null($this->txn)) return;
		fclose($this->txn['fd']);
		$this->txn = null;
	}

	public function transmitLog() {
		return $this->txlog;
	}
}


