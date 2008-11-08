<?php

namespace Daemon::PMaild::MTA::MailFilter;

use pinetd::Logger;

class AntiSpam extends Daemon::PMaild::MTA::MailFilter::MailFilter {
	protected function run_spamassassin(&$txn) {
		$flags = $this->domainFlags();
		if (isset($txn['spamassassin'])) {
			if ((isset($flags['drop_email_on_spam'])) && ($txn['spamassassin']['is_spam'])) {
				return '500 5.7.1 Mail detected as spam, and refused';
			}
			return NULL;
		}
		// run spamassassin on this mail
		$desc = array(
			0 => array('pipe', 'r'), // stdin
			1 => array('pipe', 'w'), // stdout
		);

		$proc = proc_open('spamc -x', $desc, $pipes);

		if (!$proc) {
			Logger::log(Logger::LOG_ERR, 'Failed to run spamc');
			return '400 Antispam seems down, your mail can\'t be checked, and because of that I can\'t accept it';
		}

		rewind($txn['fd']);
		stream_copy_to_stream($txn['fd'], $pipes[0]); // copy to spamassassin
		fclose($pipes[0]); // send EOF to stdin, will cause the antispam to run
		ftruncate($txn['fd'], 0);
		stream_copy_to_stream($pipes[1], $txn['fd']);
		fclose($pipes[1]);

		$res = proc_close($proc);

		switch($res) {
			case 0: break; // no problem
			case 64: // EX_USAGE
				Logger::log(Logger::LOG_ERR, 'Error in spamc command line, please check it again');
				return '400 4.0.0 Internal error, please try again later';
			case 65: // EX_DATAERR
				return '500 Formatting error in your mail';
			case 66: // EX_NOINPUT
				return '500 Antispam claims you didn\'t provide a message';
			case 67: // EX_NOUSER
				return '500 addressee unknown';
			case 68: // EX_NOHOST
				return '500 host name unknown';
			case 69: // EX_UNAVAILABLE
				Logger::log(Logger::LOG_ERR, 'Please start spamd for antispam to work');
				return '400 4.0.0 Antispam service unavailable';
			case 70: // EX_SOFTWARE
				Logger::log(Logger::LOG_ERR, 'Antispam reported error EX_SOFTWARE: internal software error');
				return '400 4.0.0 Antispam: internal software error';
			case 71: // EX_OSERR
				Logger::log(Logger::LOG_ERR, 'Antispam reported error EX_OSERR: system error');
				return '400 4.0.0 Internal Error';
			case 72: // EX_OSFILE
				Logger::log(Logger::LOG_ERR, 'Antispam reporting error EX_OSFILE: critical OS file missing');
				return '400 4.0.0 Internal Error';
			case 73: // EX_CANTCREAT
				return '400 Unexpected antispam error';
			case 74: // EX_IOERR
				return '400 4.0.0 I/O error on server while running antispam';
			case 75: // EX_TEMPFAIL
				return '400 4.0.0 Please try again later';
			case 76: // EX_PROTOCOL
				return '400 4.0.0 Remote error in protocol';
			case 77: // EX_NOPERM
				Logger::log(Logger::LOG_ERR, 'Antispam reported error EX_NOPERM: permission denied');
				return '400 4.0.0 Internal error';
			case 78: // EX_CONFIG
				Logger::log(Logger::LOG_ERR, 'Antispam reported error EX_CONFIG: configuration error. Please check config for spamassassin, spamd and apamc');
				return '400 4.0.0 Internal Error';
			default:
				rewind($txn['fd']);
				fpassthru($txn['fd']);
				Logger::log(Logger::LOG_ERR, 'Got unknown error '.$res.' from spamassassin, please do something');
				return '400 4.0.0 Internal error';
		}

		// check if spam
		rewind($txn['fd']);
		$head = array();
		$last = '';
		while(!feof($txn['fd'])) {
			$lin = rtrim(fgets($txn['fd']));
			if ($lin === '') break;
			$pos = strpos($lin, ':');
			if ($pos === false) {
				$last .= ltrim($lin);
				continue;
			}
			$h = strtolower(substr($lin, 0, $pos));
			$lin = ltrim(substr($lin, $pos+1));
			if (isset($head[$h])) {
				unset($last);
				$last = '';
				continue;
			}
			$head[$h] = $lin;
			$last = &$head[$h];
		}
		unset($last);
		$status = $head['x-spam-status'];
		if (!$status) return null;
		$status = explode(' ', $status);

		$spam = array('is_spam' => true);
		if (strtolower($status[0]) == 'no,') {
			// not spam
			$spam['is_spam'] = false;
		}
		foreach($status as $i) {
			$pos = strpos($i, '=');
			if ($pos === false) continue;
			$k = substr($i, 0, $pos);
			$spam[$k] = substr($i, $pos + 1);
		}
		$spam['tests'] = explode(',', $spam['tests']);

		$txn['spamassassin'] = $spam;

		if ($spam['is_spam'])
			if (isset($flags['drop_email_on_spam'])) return '500 5.7.1 Mail detected as spam, and refused';
		return null;
	}
}

