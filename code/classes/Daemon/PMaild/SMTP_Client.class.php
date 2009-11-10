<?php

namespace Daemon\PMaild;

class SMTP_Client extends \pinetd\TCP\Client {
	protected $helo = null;
	protected $dataMode = false;
	protected $txn;
	protected $dataTxn; // used when sending an email

	function __construct($fd, $peer, $parent, $protocol) {
		parent::__construct($fd, $peer, $parent, $protocol);
		$this->setMsgEnd("\r\n");
	}

	function welcomeUser() { // nothing to do
		return true;
	}

	function sendBanner() {
		$this->sendMsg('220 '.$this->IPC->getName().' ESMTP (pmaild v2.0.0; pinetd v'.PINETD_VERSION.')');
		$class = relativeclass($this, 'MTA\\Mail');
		$this->txn = new $class($this->peer, $this->IPC);
		return true;
	}

	function shutdown() {
		$this->sendMsg('421 4.3.2 SMTP server is shutting down, please try again later');
	}

	function _cmd_default() {
		$this->sendMsg('500 5.5.1 Unknown command');
	}

	function _cmd_quit() {
		$this->sendMsg('221 2.0.0 '.$this->IPC->getName().' closing control connexion.');
		$this->close();
	}

	function _cmd_expn($argv) {
		return $this->_cmd_vrfy($argv);
	}

	function _cmd_vrfy($argv) {
		$this->sendMsg('502 5.5.1 I won\'t let you check if this email exists, however RFC said I should reply with a 502 message.');
	}

	function _cmd_rset() {
		$this->txn->reset();
		$this->sendMsg('250 RSET done');
	}

	function _cmd_noop($argv) {
		$this->sendMsg('250 2.0.0 Ok');
	}

	function _cmd_ehlo($argv) {
		$argv[0] = 'EHLO';
		return $this->_cmd_helo($argv);
	}

	function _cmd_helo($argv) {
		if (!is_null($this->helo)) {
			$this->sendMsg('503 5.5.2 You already told me hello, remember?');
			return;
		}
		if (!$this->txn->setHelo($argv[1])) {
			$this->sendMsg('500 5.5.2 Bad HELO, please retry...');
			return;
		}
		$this->helo = $argv[1];
		if ($argv[0] != 'EHLO') {
			$this->sendMsg('250 '.$this->IPC->getName().' pleased to meet you, '.$this->helo);
			return;
		}
		$this->sendMsg('250-'.$this->IPC->getName().' pleased to meet you, '.$this->helo);
		$this->sendMsg('250-PIPELINING');
		$this->sendMsg('250-ENHANCEDSTATUSCODES');
		$this->sendMsg('250-ETRN');
		$this->sendMsg('250-TXLG');
		if (($this->IPC->hasTLS()) && ($this->protocol != 'tls')) {
			$this->sendMsg('250-STARTTLS');
		}
		if ( ($this->protocol == 'tls') || (!$this->IPC->hasTLS())) {
			$this->sendMsg('250-AUTH PLAIN LOGIN');
			$this->sendMsg('250-AUTH=PLAIN LOGIN'); // for deaf clients
		}
		$this->sendMsg('250 8BITMIME');
	}

	// AUTH: http://www.technoids.org/saslmech.html
	function _cmd_auth($argv) {
		if (($this->protocol == 'tcp') && ($this->IPC->hasTLS())) {
			$this->sendMsg('550 5.7.0 SSL required before using AUTH');
			return;
		}
		switch(strtoupper($argv[1])) {
			case 'LOGIN':
				$this->sendMsg('334 '.base64_encode('Username:'));
				$login = base64_decode($this->readLine());
				$this->sendMsg('334 '.base64_encode('Password:'));
				$pass = base64_decode($this->readLine());
				break;
			case 'PLAIN':
				if ($argv[2]) {
					list($check, $login, $pass) = explode("\x00", base64_decode($argv[2]));
				} else {
					$this->sendMsg('334');
					list($check, $login, $pass) = explode("\x00", base64_decode($this->readLine()));
				}
				if ($check != '') {
					$this->sendMsg('550 5.5.2 Syntax error in AUTH PLAIN');
				 	return;
				}
				break;
			default:
				$this->sendMsg('550 5.5.2 Unsupported auth method');
				return;
		}
		// we got $login & $pass
		if (!$this->txn->setLogin($login, $pass)) {
			sleep(4); // TODO: watch for DoS here if Fork is disabled! Do not sleep if no fork?
			$this->sendMsg('535 5.7.0 Authentification failed');
			return;
		}
		$this->SendMsg('235 2.0.0 OK Authenticated');
	}

	function _cmd_help() {
		$this->sendMsg('214 http://wiki.ooKoo.org/wiki/pinetd');
	}

	function _cmd_starttls() {
		if (!$this->IPC->hasTLS()) {
			$this->sendMsg('454 TLS not available');
			return;
		}
		if ($this->protocol != 'tcp') {
			$this->sendMsg('500 STARTTLS only available in PLAIN mode. An encryption mode is already enabled');
			return;
		}
		$this->sendMsg('220 Ready to start TLS');
		// TODO: this call will lock, need a way to avoid from doing it without Fork
		if (!stream_socket_enable_crypto($this->fd, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			$this->sendMsg('500 TLS negociation failed!');
			$this->close();
		}
		$this->helo = NULL; // reset as per RFC-2487 5.2
		$this->protocol = 'tls';
		$this->txn->reset();
	}

	function _cmd_mail($argv) {
		if (is_null($this->helo)) {
			$this->sendMsg('503 Sorry man, can\'t let you send mails without EHLO/HELO first');
			return;
		}
		$nargv = 2;
		if (strtoupper($argv[1]) != 'FROM:') {
			if (strtoupper(substr($argv[1], 0, 5)) == 'FROM:') {
				$from = substr($argv[1], 5);
			} else {
				$this->sendMsg('550 Invalid syntax. Expected MAIL FROM: <user@domain.tld>');
				return;
			}
		} else {
			$from = (string)$argv[$nargv++];
		}
		// in theory, addr should be in < > (still, we won't require it)
		if (($from[0] == '<') && (substr($from, -1) == '>')) {
			$from = substr($from, 1, -1);
		}
		// TODO: we might have BODY=8BITMIME in next argv
		if (!$this->txn->setFrom($from)) {
			$this->sendMsg($this->txn->errorMsg());
			return;
		}
		$this->sendMsg('250 2.1.0 Originator <'.$from.'> OK');
	}

	function _cmd_rcpt($argv) {
		if (is_null($this->helo)) {
			$this->sendMsg('503 Sorry man, can\'t let you send mails without EHLO/HELO first');
			return;
		}
		$nargv = 2;
		if (strtoupper($argv[1]) != 'TO:') {
			if (strtoupper(substr($argv[1], 0, 3)) == 'TO:') {
				$from = substr($argv[1], 3);
			} else {
				$this->sendMsg('550 Invalid syntax. Expected RCPT TO: <user@domain.tld>');
				return;
			}
		} else {
			$from = $argv[$nargv++];
		}
		// in theory, addr should be in < > (still, we won't require it)
		if (($from[0] == '<') && (substr($from, -1) == '>')) {
			$from = substr($from, 1, -1);
		}
		// TODO: we might have stuff in next argv
		if (!$this->txn->addTarget($from)) {
			$this->sendMsg($this->txn->errorMsg());
			return;
		}
		$this->sendMsg('250 2.5.0 Target <'.$from.'> OK');
	}

	function _cmd_data() {
		// pipelining -> check that buffer is EMPTY (if not, this may be a HTTP-proxy-attack)
		if ($this->buf !== false) {
			var_dump($this->buf);
			$this->sendMsg('550 Invalid use of pipelining, you can\'t pipeline a mail content');
			return;
		}
		$this->dataTxn = $this->txn->sendMail();
		if (!is_array($this->dataTxn)) {
			$this->sendMsg('450 Internal error, please try again later');
			return;
		}
		$this->sendMsg('354 Enter message, ending with "." on a line by itself (CR/LF)');
		$this->dataMode = true;
	}

	function parseDataLine($lin) {
		// we got one line of data
		// check line ending
		if (substr($lin, -2) != "\r\n") {
			$this->txn->cancelMail();
			$this->sendMsg('550 Sorry, you are supposed to end lines with <CR><LF>, which seems to not be the case right now');
			$this->dataMode = false;
			return;
		}
		if (rtrim($lin) != '.') { // only one dot in one line ?
			if (($lin[0] == '.') && ($lin[1] == '.')) $lin = substr($lin, 1); // strip trailing dots
			fputs($this->dataTxn['fd'], $lin); // still have its linebreak
			return;
		}

		// got whole mail, it's time for checks!
		$this->dataMode = false;

		if (!$this->txn->finishMail()) { // failed at sending the mail? :(
			$this->sendMsg($this->txn->errorMsg());
			$this->txn->reset();
			return;
		}
		$this->txn->reset();
		$this->sendMsg('250 2.5.0 Ok, have a nice day');
	}

	function _cmd_txlg() {
		$list = $this->txn->transmitLog();
		foreach($list as $mail => $err) {
			if (is_null($err)) $err = '250 2.5.0 Ok, have a nice day';
			$this->sendMsg('250-<'.$mail.'>: '.$err);
		}
		$this->sendMsg('250 End of list');
	}

	protected function parseLine($lin) {
		if ($this->dataMode)
			return $this->parseDataLine($lin);
		return parent::parseLine($lin);
	}

	public function close() {
		if ($this->dataMode) {
			$this->txn->cancelMail();
			$this->dataMode = false;
		}
		return parent::close();
			
	}
}


