<?php

namespace Daemon\PMaild;

use pinetd\Logger;
use pinetd\SQL;

class MTA_Child extends \pinetd\ProcessChild {
	protected $sql;
	protected $localConfig;
	protected $mail = null;
	protected $ch;

	public function mainLoop($IPC) {
		$this->IPC = $IPC;
		$this->IPC->setParent($this);
		$this->localConfig = $this->IPC->getLocalConfig();
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		$this->processBaseName = get_class($this);
		$this->setProcessStatus();
		while(1) {
			$this->IPC->selectSockets(0);
			// get a mail for me
			$req = 'SELECT `mlid`, `to` FROM `mailqueue` WHERE (`next_attempt` < NOW() OR `next_attempt` IS NULL) AND `pid` IS NULL ORDER BY `next_attempt` IS NULL, `next_attempt` LIMIT 10';
			$res = $this->sql->query($req);
			if (!$res) break;
			if ($res->num_rows < 1) break;
			while($row = $res->fetch_assoc()) {
				$req = 'UPDATE `mailqueue` SET `last_attempt` = NOW(), `pid` = \''.$this->sql->escape_string(getmypid()).'\' WHERE `mlid` = \''.$this->sql->escape_string($row['mlid']).'\' AND `to` = \''.$this->sql->escape_string($row['to']).'\' AND `pid` IS NULL';
				if (!$this->sql->query($req)) break;
				if ($this->sql->affected_rows < 1) continue; // missed it
				$DAO_mailqueue = $this->sql->DAO('mailqueue', array('mlid', 'to'));
				$mail = $DAO_mailqueue->loadByField($row);
				if (!$mail) continue; // ?!
				$mail = $mail[0];
				$this->track($mail, 'PROCESSING', '200 Mail being processed');
				$this->setProcessStatus($mail->to);
				$this->mail = $mail;
				// ok, we got our very own mlid
				try {
					$this->processEmail($mail);
					$this->mail = null;
				} catch(\Exception $e) {
					Logger::log(Logger::LOG_WARN, (string)$e);
					$this->mail = null;
					break;
				}
			}
		}
	}

	protected function track($mail, $status, $status_message, $host = 'none') {
		if (!$mail->tracker) return;

		if (!$this->ch) {
			$this->ch = curl_init();
			curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('X-Ipc: MAIL_TRACK'));
		}
		curl_setopt($this->ch, CURLOPT_URL, $mail->tracker);
		$query = array(
			'mlid' => $mail->mlid,
			'status' => $status,
			'status_host' => $host,
			'status_message' => $status_message,
		);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($query, '', '&'));
		curl_exec($this->ch); // send query
	}

	public function shutdown() {
		// TODO: handle more CLEAN exit!
		$this->mail->pid = null;
		$this->mail->commit();
		exit;
	}

	protected function mailPath($uniq) {
		$path = $this->localConfig['Mails']['Path'].'/mailqueue';
		if ($path[0] != '/') $path = PINETD_ROOT . '/' . $path; // make it absolute
		$path.='/'.$uniq;
		return $path;
	}

	protected function processEmail($mail) {
		// we got from, we got to, analyze to
		$file = $this->mailPath($mail->mlid);
		$target = imap_rfc822_parse_adrlist($mail->to, '');
		$host = $target[0]->host;
		$this->track($mail, 'DNS_RESOLVE', '200 Resolving DNS for host '.$host);
		$mx = dns_get_record($host, DNS_MX);

		$count = 0;
		$mxlist = array();
		if ($mx) {
			foreach($mx as $mxhost) {
				if ($mxhost['type'] != 'MX') continue;
				$mxlist[$mxhost['target']] = $mxhost['pri'];
			}
		}
		$this->error = array();
		if (!$mxlist) { // No mx, RFC 2821 says to use the host itself with pri=0
			$mxlist[$host] = 0;
		}
		asort($mxlist);
		$status = 200;
		foreach($mxlist as $mx=>$pri) {
			try {
				if($this->processEmailMX($mx, $mail)) {
					// success -> cleanup a bit
					$mail->delete();
					$DAO_mailqueue = $this->sql->DAO('mailqueue', array('mlid', 'to'));
					$res = $DAO_mailqueue->loadByField(array('mlid' => $mail->mlid));
					if (!$res) @unlink($file); // cleanup
					return true;
				}
			} catch(\Exception $e) {
				Logger::log(Logger::LOG_INFO, 'MX '.$mx.' refused mail: '.$e->getMessage());
				$this->track($mail, 'ERROR', $e->getMessage(), $mx);
				$this->error[$mx] = $e;
				$status = (string)$e->getCode();
				if ($status[0] =='5') break; // fatal error
			}
		}
		if (($mail->attempt_count > $this->localConfig['MTA']['MaxAttempt']) || ($status[0] == '5')) {
			// TODO: handle Errors-To header field
			if (!is_null($mail->from)) {
				$class = relativeclass($this, 'MTA\\Mail');
				$txn = new $class(null, $this->IPC);
				$txn->setHelo($this->IPC->getName());
				$txn->setFrom(''); // no return path for errors
				$txn->addTarget($mail->from);
				$p = $txn->sendMail();
				fputs($p['fd'], 'Message-ID: <'.$mail->mlid.'-'.time().'@'.$this->IPC->getName().">\r\n");
				fputs($p['fd'], 'Date: '.date(DATE_RFC2822)."\r\n");
				fputs($p['fd'], 'From: MAILER-DAEMON <postmaster@'.$this->IPC->getName().">\r\n");
				fputs($p['fd'], 'X-User-Agent: pMaild v2.0.0'."\r\n");
				fputs($p['fd'], 'To: '.$mail->from."\r\n");
				fputs($p['fd'], 'Subject: Failed attempt to send email'."\r\n");
				fputs($p['fd'], "\r\n"); // end of headers
				fputs($p['fd'], "Hello,\r\n\r\nHere is the mailer daemon at ".$this->IPC->getName().". I'm sorry\r\n");
				fputs($p['fd'], "I couldn't transmit your mail. This is a fatal error, so I gave up\r\n\r\n");
				fputs($p['fd'], "Here are some details about the error:\r\n");
				foreach($this->error as $mx=>$msg) {
					fputs($p['fd'], '  '.$mx.': '.$msg->getMessage()."\r\n\r\n");
				}
				fputs($p['fd'], 'While delivering to: '.$mail->to."\r\n\r\n");
				$in = fopen($file, 'r');
				if ($in) {
					fputs($p['fd'], "And here are the headers of your mail, for reference:\r\n\r\n");
					while(!feof($in)) {
						$lin = fgets($in);
						if (rtrim($lin) == '') break;
						fputs($p['fd'], $lin);
					}
					fclose($in);
				}
				$txn->finishMail($this->IPC);
			}
			@unlink($file);
			$mail->delete();
			return;
//			throw new \Exception('Mail '.$mail->mlid.' for '.$mail->to.' refused: '.$info['last_error']);
		}
		// TODO: handle non-fatal errors and mail a delivery status *warning* if from is not null
		if ($mail->attempt_count == floor($this->localConfig['MTA']['MaxAttempt']*0.33)) {
			// this is thirddelivery, may be good to warn user
			if (!is_null($mail->from)) {
				$class = relativeclass($this, 'MTA\\Mail');
				$txn = new $class(null, $this->IPC);
				$txn->setHelo($this->IPC->getName());
				$txn->setFrom(''); // no return path for errors
				$txn->addTarget($mail->from);
				$p = $txn->sendMail();
				fputs($p['fd'], 'Message-ID: <'.$mail->mlid.'-'.time().'@'.$this->IPC->getName().">\r\n");
				fputs($p['fd'], 'Date: '.date(DATE_RFC2822)."\r\n");
				fputs($p['fd'], 'From: MAILER-DAEMON <postmaster@'.$this->IPC->getName().">\r\n");
				fputs($p['fd'], 'X-User-Agent: pMaild v2.0.0'."\r\n");
				fputs($p['fd'], 'To: '.$mail->from."\r\n");
				fputs($p['fd'], 'Subject: Warning: mail still pending, last attempt to send email failed'."\r\n");
				fputs($p['fd'], "\r\n"); // end of headers
				fputs($p['fd'], "Hello,\r\n\r\nHere is the mailer daemon at ".$this->IPC->getName().". I'm sorry\r\n");
				fputs($p['fd'], "I couldn't transmit your mail yet. This is NOT a fatal error, and I still have to retry a few times\r\n\r\n");
				fputs($p['fd'], "Here are some details about the warning:\r\n");
				foreach($this->error as $mx=>$msg) {
					fputs($p['fd'], '  '.$mx.': '.$msg->getMessage()."\r\n\r\n");
				}
				fputs($p['fd'], 'While delivering to: '.$mail->to."\r\n\r\n");
				$in = fopen($file, 'r');
				if ($in) {
					fputs($p['fd'], "And here are the headers of your mail, for reference:\r\n\r\n");
					while(!feof($in)) {
						$lin = fgets($in);
						if (rtrim($lin) == '') break;
						fputs($p['fd'], $lin);
					}
					fclose($in);
				}
				$txn->finishMail($this->IPC);
			}
		}

		// compute average retry time
		$maxlifetime = $this->localConfig['MTA']['MailMaxLifetime'] * 3600; // initially exprimed in hours
		$maxattempt = $this->localConfig['MTA']['MaxAttempt'];
		$avgwait = $maxlifetime / $maxattempt; // average wait time
		$mailpos = (float)$mail->attempt_count / $maxattempt; // position in queue as float, 0-1
		if ($mailpos > 1) $mailpos = -0.1;
		if ($mailpos == 0) $mailpos = -0.5; // if this is the first attempt, retry immediatly to avoid delays due to greylisting
		$shouldwait = $avgwait * ($mailpos + 0.5); // time we should wait for next attempt
		// store failure into db
		$mail->attempt_count ++;
		$mail->pid = null;
		$mail->next_attempt = $this->sql->timeStamp(time() + 3600);
		$mail->last_error = (string)array_pop($this->error);
		$mail->commit();
		return false;
	}

	protected function readMxAnswer($sock, $expect = 2, $saferead = false) {
		$res = array();
		while(1) {
			$lin = fgets($sock);
			if ($lin === false) throw new \Exception('Could not read from peer!', 400);
			$lin = rtrim($lin);
			$code = substr($lin, 0, 3);
			if ($lin[0] != $expect) throw new \Exception($lin, $code);
			$res[] = substr($lin, 4);
			if ($lin[3] != '-') break;
		}
		if (!$saferead) $this->IPC->selectSockets(0);
		return $res;
	}

	protected function writeMx($sock, $msg) {
		fputs($sock, $msg."\r\n");
	}

	protected function processEmailMX($host, $mail) {
		$file = $this->mailPath($mail->mlid);
		if (!file_exists($file)) throw new \Exception('Mail queued but file not found: '.$mail->mlid, 500);
		$size = filesize($file);
		$ssl = false;
		$capa = array();
		$this->IPC->selectSockets(0);
		$this->track($mail, 'CONNECT', '200 Connecting to host', $host);
		$sock = fsockopen($host, 25, $errno, $errstr, 30);
		stream_set_timeout($sock, 120); // 120 secs timeout on read
		if (!$sock) throw new \Exception('Connection failed: ['.$errno.'] '.$errstr, 400); // not fatal (400)
		$this->IPC->selectSockets(0);
		$this->readMxAnswer($sock); // hello man
		try {
			$this->writeMx($sock, 'EHLO '.$this->IPC->getName());
			$ehlo = $this->readMxAnswer($sock);
		} catch(\Exception $e) {
			// no ehlo? try helo
			$this->writeMx($sock, 'RSET');
			$this->readMxAnswer($sock);
			$this->writeMx($sock, 'HELO '.$this->IPC->getName());
			$this->readMxAnswer($sock);
			$ehlo = array();
		}

		// first, check for STARTTLS (will override $ehlo if present)
		foreach($ehlo as $cap) {
			if (strtoupper($cap) == 'STARTTLS') {
				// try to start ssl
				try {
					$this->writeMx($sock, 'STARTTLS');
					$this->readMxAnswer($sock);
				} catch(\Exception $e) {
					continue; // ignore it
				}
				if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					throw new \Exception('Failed to enable TLS on stream', 400);
				}
				$ssl = true;
				// need to EHLO again
				$this->writeMx($sock, 'EHLO '.$this->IPC->getName());
				$ehlo = $this->readMxAnswer($sock);
				break;
			}
		}

		// once more!
		foreach($ehlo as $cap) {
			$cap = strtolower($cap);
			if (substr($cap, 0, 5) == 'size ') {
				$capa['size'] = true;
				$maxsize = substr($cap, 5);
				if ($size > $maxsize) {
					throw new \Exception('Mail is too big for remote system', 500);
				}
			}
		}

		if ($capa['size']) {
			$this->writeMx($sock, 'MAIL FROM:<'.$mail->from.'> SIZE='.$size);
		} else {
			$this->writeMx($sock, 'MAIL FROM:<'.$mail->from.'>');
		}
		$this->readMxAnswer($sock);
		$this->writeMx($sock, 'RCPT TO:<'.$mail->to.'>');
		$this->readMxAnswer($sock);
		$this->track($mail, 'TRANSMIT', '200 Transmitting email', $host);
		$this->writeMx($sock, 'DATA');
		$this->readMxAnswer($sock, 3);
		$fp = fopen($file, 'r');
		//
		$class = relativeclass($this, 'MTA\\Mail');
		$MTA_Mail = new $class(null, $this->IPC);
		fputs($sock, $MTA_Mail->header('Received', '(PMaild MTA '.getmypid().' on '.$this->IPC->getName().' processing mail to '.$host.($ssl?' with TLS enabled':'').'); '.date(DATE_RFC2822)));
		while(!feof($fp)) {
			$lin = fgets($fp);
			if ($lin[0] == '.') $lin = '.'.$lin;
			fputs($sock, $lin);
		}
		$this->writeMx($sock, '.');
		$final = $this->readMxAnswer($sock, 2, true);
		$this->track($mail, 'SUCCESS', $final[0], $host);
		$this->writeMx($sock, 'QUIT');
		try {
			$this->readMxAnswer($sock, 2, true);
		} catch(\Exception $e) {
			// even if you complain now, it's too late
		}
		fclose($sock);
		return true;
	}
}

