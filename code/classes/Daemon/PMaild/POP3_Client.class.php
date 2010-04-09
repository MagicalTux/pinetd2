<?php

namespace Daemon\PMaild;
use pinetd\SQL;

class POP3_Client extends \pinetd\TCP\Client {
	protected $login = null;
	protected $info = null;
	protected $loggedin = false;
	protected $localNum = array(0 => null);
	protected $toDelete = array(); // list of stuff that should be deleted on QUIT, and only on QUIT
	protected $sql;
	protected $localConfig;

	function __construct($fd, $peer, $parent, $protocol) {
		parent::__construct($fd, $peer, $parent, $protocol);
		$this->setMsgEnd("\r\n");
	}

	function welcomeUser() { // nothing to do
		return true;
	}

	function sendBanner() {
		$this->sendMsg('+OK '.$this->IPC->getName().' POP3 (pmaild v2.0.0; pinetd v'.PINETD_VERSION.')');
		$this->localConfig = $this->IPC->getLocalConfig();
		return true;
	}

	function shutdown() {
		$this->sendMsg('-ERR POP3 server is shutting down, please try again later');
	}

	protected function identify($pass) { // login in $this->login
		$class = relativeclass($this, 'MTA\Auth');
		$auth = new $class($this->localConfig);
		$this->loggedin = $auth->login($this->login, $pass, 'pop3');
		if (!$this->loggedin) return false;
		$this->login = $auth->getLogin();
		$info = $auth->getInfo();
		$this->info = $info;
		// link to MySQL
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		return true;
	}

	// POP3 is rather annoying, we need to have our own list of ids specific to the current session ONLY
	protected function getLocalNum($id) {
		$tnum = array_search($id, $this->localNum);
		if ($tnum !== false) return $tnum; // found
		// allocate an id
		$this->localNum[] = $id;
		// return id
		return array_search($id, $this->localNum);
	}

	protected function resolveLocalNum($tnum) {
		return $this->localNum[$tnum];
	}

	protected function mailPath($uniq) {
		$path = $this->localConfig['Mails']['Path'].'/domains';
		if ($path[0] != '/') $path = PINETD_ROOT . '/' . $path; // make it absolute
		$id = $this->info['domainid'];
		$id = str_pad($id, 10, '0', STR_PAD_LEFT);
		$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		$id = $this->info['account']->id;
		$id = str_pad($id, 4, '0', STR_PAD_LEFT);
		$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		$path.='/'.$uniq;
		return $path;
	}

	function _cmd_default() {
		$this->sendMsg('-ERR Unknown command');
	}

	function _cmd_quit() {
		if ($this->loggedin) {
			// delete messages
			foreach($this->toDelete as $id => $mail) {
				$mailid = $mail->mailid;
				$file = $this->mailPath($mail->uniqname);
				unlink($file);
				clearstatcache();
				if (file_exists($file)) continue; // could not delete?
				$mail->delete();
				$this->sql->DAO('z'.$this->info['domainid'].'_mime', 'mimeid')->delete(array('userid'=>$this->info['account']->id, 'mailid'=>$mailid));
				$this->sql->DAO('z'.$this->info['domainid'].'_mime_header', 'headerid')->delete(array('userid'=>$this->info['account']->id, 'mailid'=>$mailid));
			}

			// Extra: update mail_count and mail_quota (for this user)
			try {
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_count` = (SELECT COUNT(1) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_quota` = (SELECT SUM(b.`size`) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
			} catch(Exception $e) {
				// ignore it
			}
		}

		$this->sendMsg('+OK '.$this->IPC->getName().' closing control connexion.');
		$this->close();
	}

	function _cmd_stls() {
		if (!$this->IPC->hasTLS()) {
			$this->sendMsg('-ERR SSL not available');
			return;
		}
		if ($this->protocol != 'tcp') {
			$this->sendMsg('-ERR STLS only available in PLAIN mode. An encryption mode is already enabled');
			return;
		}
		$this->sendMsg('+OK Begin TLS negotiation');
		// TODO: this call will lock, need a way to avoid from doing it without Fork
		if (!stream_socket_enable_crypto($this->fd, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			$this->sendMsg('-ERR TLS negociation failed!');
			$this->close();
		}
		$this->protocol = 'tls';
	}

	function _cmd_auth($argv) {
		if ($this->loggedin) {
			$this->sendMsg('-ERR You do not need to AUTH two times');
			return;
		}
		if (($this->IPC->requireSsl()) && ($this->protocol == 'tcp')) return $this->sendMsg('-ERR Need SSL before logging in');
		if (!$argv[1]) {
			$this->sendMsg('+OK list of SASL extensions follows');
			$this->sendMsg('PLAIN');
			$this->sendMsg('.');
			return;
		}
		if ($argv[1] != 'PLAIN') {
			$this->sendMsg('-ERR Unknown AUTH method');
			return;
		}
		if (!$argv[2]) { // AUTH PLAIN <response>, or given on different line...
			$this->sendMsg('+ go ahead');
			$argv[2] = $this->readLine(); // TODO: not fork-compliant
		}
		$response = base64_decode($argv[2]);
		if ($response[0] != "\0") {
			$this->sendMsg('-ERR syntax error');
			return;
		}
		$response = explode("\0", $response);
		$this->login = $response[1];
		if ($this->identify($response[2])) {
			$this->sendMsg('+OK Authorization granted');
		} else {
			sleep(4);
			$this->sendMsg('-ERR Authorization denied');
		}
	}

	function _cmd_user($argv) {
		if ($this->loggedin) {
			$this->sendMsg('-ERR already logged in');
			return;
		}
		if (($this->IPC->requireSsl()) && ($this->protocol == 'tcp')) return $this->sendMsg('-ERR Need SSL before logging in');
		if (count($argv) < 2) {
			$this->sendMsg('-ERR Syntax: USER <login>');
			return;
		}
		$this->login = $argv[1];
		$this->sendMsg('+OK Provide PASSword now, please');
	}

	function _cmd_pass($argv) {
		if ($this->loggedin) {
			$this->sendMsg('-ERR Already logged in');
			return;
		}
		if (($this->IPC->requireSsl()) && ($this->protocol == 'tcp')) return $this->sendMsg('-ERR Need SSL before logging in');
		if (count($argv) < 2) {
			$this->sendMsg('-ERR Syntax: PASS <password>');
			return;
		}
		if ($this->identify($argv[1])) {
			$this->sendMsg('+OK Authorization granted');
		} else {
			sleep(4);
			$this->sendMsg('-ERR Authorization denied');
		}
	}

	function _cmd_noop() {
		if (!$this->loggedin) return $this->sendMsg('-ERR need to login first');
		$this->sendMsg('+OK'); // nothing :D
	}

	function _cmd_rset() {
		if (!$this->loggedin) return $this->sendMsg('-ERR need to login first');
		$this->toDelete = array();
		$this->sendMsg('+OK');
	}

	function _cmd_stat() {
		if (!$this->loggedin) return $this->sendMsg('-ERR need to login first');
		$this->sendMsg('+OK '.$this->info['account']->mail_count.' '.$this->info['account']->mail_quota);
	}

	function _cmd_list($argv) {
		if (!$this->loggedin) return $this->sendMsg('-ERR need to login first');
		$cond = array('userid' => $this->info['account']->id);
		$onlyone = false;
		if ($argv[1]) {
			$id = $argv[1];
			if (isset($this->toDelete[$id])) {
				$this->sendMsg('-ERR Message was deleted, you can still restore it using RSET');
				return;
			}
			$tid = $this->resolveLocalNum($id);
			if (is_null($tid)) {
				$this->sendMsg('-ERR Unknown message');
				return;
			}
			$cond['mailid'] = $tid;
			$onlyone = true;
		} else {
			$this->sendMsg('+OK'); // list...
		}
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$list = $DAO_mails->loadByField($cond);
		foreach($list as $mail) {
			$flags = array_flip(explode(',', $mail->flags));
			if (isset($flags['deleted'])) continue;
			$num = $this->getLocalNum($mail->mailid);
			if (isset($this->toDelete[$num])) continue;
			$file = $this->mailPath($mail->uniqname);
			if (!file_exists($file)) {
				$mail->delete();
				continue;
			}
			$s = filesize($file);
			if ($onlyone) {
				$this->sendMsg('+OK '.$num.' '.$s);
				return;
			}
			$this->sendMsg($num.' '.$s);
		}
		$this->sendMsg('.');
	}

	function _cmd_uidl($argv) {
		if (!$this->loggedin) return $this->sendMsg('-ERR need to login first');
		$cond = array('userid' => $this->info['account']->id);
		$onlyone = false;
		if ($argv[1]) {
			$id = $argv[1];
			if (isset($this->toDelete[$id])) {
				$this->sendMsg('-ERR Message was deleted, you can still restore it using RSET');
				return;
			}
			$tid = $this->resolveLocalNum($id);
			if (is_null($tid)) {
				$this->sendMsg('-ERR Unknown message');
				return;
			}
			$cond['mailid'] = $tid;
			$onlyone = true;
		} else {
			$this->sendMsg('+OK'); // list...
		}
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$list = $DAO_mails->loadByField($cond);
		foreach($list as $mail) {
			$flags = array_flip(explode(',', $mail->flags));
			if (isset($flags['deleted'])) continue;
			$num = $this->getLocalNum($mail->mailid);
			if (isset($this->toDelete[$num])) continue;
			$s = $mail->uniqname;
			if ($onlyone) {
				$this->sendMsg('+OK '.$num.' '.$s);
				return;
			}
			$this->sendMsg($num.' '.$s);
		}
		$this->sendMsg('.');
	}

	function _cmd_retr($argv) {
		$id = (int)$argv[1];
		if (isset($this->toDelete[$id])) {
			$this->sendMsg('-ERR Message was deleted');
			return;
		}
		$tid = $this->resolveLocalNum($id);
		if (is_null($tid)) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$mail = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'mailid' => $tid));
		if (!$mail) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$mail = $mail[0];
		$flags = array_flip(explode(',', $mail->flags));
		if (isset($flags['deleted'])) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$file = $this->mailPath($mail->uniqname);
		$fd = fopen($file, 'r');
		if (!$fd) { // something is wrong with filesystem
			$this->sendMsg('+OK');
			$this->sendMsg('.');
			return;
		}
		$this->sendMsg('+OK');
		while(!feof($fd)) {
			$lin = fgets($fd);
			if ($lin[0] == '.') fputs($this->fd, '.');
			fputs($this->fd, $lin);
		}
		fclose($fd);
		$this->sendMsg('.');
		// mark the message as "read"
		$flags = array_flip(explode(',', $mail->flags));
		if (isset($flags['recent'])) {
			unset($flags['recent']);
			$mail->flags = implode(',', array_flip($flags));
			$mail->commit();
		}
	}

	function _cmd_top($argv) {
		// return headers plus n lines of mail
		$id = (int)$argv[1];
		$count = (int)$argv[2];
		if (isset($this->toDelete[$id])) {
			$this->sendMsg('-ERR Message was deleted');
			return;
		}
		$tid = $this->resolveLocalNum($id);
		if (is_null($tid)) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$mail = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'mailid' => $tid));
		if (!$mail) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$mail = $mail[0];
		$flags = array_flip(explode(',', $mail->flags));
		if (isset($flags['deleted'])) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$file = $this->mailPath($mail->uniqname);
		$fd = fopen($file, 'r');
		if (!$fd) { // something is wrong with filesystem
			$this->sendMsg('+OK');
			$this->sendMsg('.');
			return;
		}
		$this->sendMsg('+OK');
		$h = true;
		while(!feof($fd)) {
			$lin = fgets($fd);
			if ($h) {
				if (rtrim($lin) === '') $h = false;
			} else {
				if($count--<=0) break;
			}
			if ($lin[0] == '.') fputs($this->fd, '.');
			fputs($this->fd, $lin);
		}
		fclose($fd);
		$this->sendMsg('.');
		// mark the message as "read"
		$flags = array_flip(explode(',', $mail->flags));
		if (isset($flags['recent'])) {
			unset($flags['recent']);
			$mail->flags = implode(',', array_flip($flags));
			$mail->commit();
		}
	}

	function _cmd_dele($argv) {
		$id = (int)$argv[1];
		if (isset($this->toDelete[$id])) {
			$this->sendMsg('-ERR Message was deleted');
			return;
		}
		$tid = $this->resolveLocalNum($id);
		if (is_null($tid)) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$mail = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'mailid' => $tid));
		if (!$mail) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$mail = $mail[0];
		$flags = array_flip(explode(',', $mail->flags));
		if (isset($flags['deleted'])) {
			$this->sendMsg('-ERR Message not found');
			return;
		}
		$this->toDelete[$id] = $mail;
		$this->sendMsg('+OK Nessage marked for deletion');
	}

	function _cmd_capa($argv) {
		$capa = array(
			'TOP',
//			'RESP-CODES',
			'PIPELINING',
			'UIDL',
			'IMPLEMENTATION pMaild 2.0',
//			'AUTH-RESP-CODE',
		);
		if ($this->protocol != 'tcp') {
			$capa[] = 'USER';
			$capa[] = 'SASL PLAIN'; // SASL CRAM-MD5 DIGEST-MD5 PLAIN
		} else {
			$capa[] = 'STLS';
		}
		$this->sendMsg('+OK');
		foreach($capa as $cap) $this->sendMsg($cap);
		$this->sendMsg('.');
	}
}


