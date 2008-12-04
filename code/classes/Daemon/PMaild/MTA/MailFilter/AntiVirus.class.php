<?php

namespace Daemon\PMaild\MTA\MailFilter;

use pinetd\Logger;

class AntiVirus extends \Daemon\PMaild\MTA\MailFilter\MailFilter {
	protected function run_clam(&$txn) {
		if (isset($txn['clam'])) {
			if ($txn['clam'] === false) return NULL;
			return '500 5.7.1 Virus '.$txn['clam'].' found in your mail, refusing mail...';
		}
		// run clam antivirus on this mail
		$sock = @fsockopen('unix:///var/run/clamav/clamd.sock', 0); // clamd
		if (!$sock)
			$sock = @fsockopen('localhost', 3310); // clamd
		if (!$sock) {
			Logger::log(Logger::LOG_ERR, 'Failed to connect to clamd while trying to check an email for viruses');
			return '400 Antivirus seems down, your mail can\'t be checked, and because of that I can\'t accept it';
		}
		fputs($sock, "SESSION\n");
		fputs($sock, "nVERSION\n");
		$clam_version = rtrim(fgets($sock));
		fputs($sock, "nSTREAM\n"); // send file to clamd using stream
		$port = rtrim(fgets($sock));
		if (substr($port, 0, 5) != 'PORT ') {
			fputs($sock, "END\n");
			return '400 Problem while communicating with clamd';
		}
		$sock_stream = fsockopen('localhost', substr($port, 5));
		if (!$sock_stream) {
			fputs($sock, "END\n");
			return '400 Problem while communicating with clamd';
		}
		rewind($txn['fd']);
		stream_copy_to_stream($txn['fd'], $sock_stream); // copy to out
		fclose($sock_stream);

		$res = fgets($sock); // answer from clamd
		fputs($sock, "END\n");
		fclose($sock);

		$res = explode(':', $res); // stream: ANSWER
		if (count($res)>1) array_shift($res);
		$res=trim(implode(':',$res));
		if ($res=='OK') {
			$out = fopen('php://temp', 'r+');
			rewind($txn['fd']);
			stream_copy_to_stream($txn['fd'], $out);
			ftruncate($txn['fd'], 0);
			rewind($out);
			fputs($txn['fd'], Daemon\PMaild\MTA\Mail::header('X-AntiVirus-ClamAV', 'clean; '.$clam_version.' on '.date(DATE_RFC2822)));
			stream_copy_to_stream($out, $txn['fd']);
			fclose($out);
			$txn['clam'] = false;
			return NULL;
		}

		$virus=str_replace(' FOUND','',$res);
		if ($virus != $res) {
			$txn['clam'] = $virus;
			return '500 5.7.1 Virus '.$virus.' found in your mail, refusing mail...';
		}
		return '400 4.0.0 Antivirus unknown answer: '.$res;
	}
}

