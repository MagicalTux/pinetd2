<?php

namespace Daemon\PMaild\MTA\MailFilter;

use pinetd\Logger;
use \Exception;

class AntiVirus extends \Daemon\PMaild\MTA\MailFilter\MailFilter {
	private function _clam_read($sock, &$reqid = NULL) {
		$res = rtrim(fgets($sock));
		$pos = strpos($res, ':');
		if ($pos === false) throw new Exception('Malformed reply from clamd');

		$reqid = substr($res, 0, $pos);
		return ltrim(substr($res, $pos+1));
	}

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
		fputs($sock, "nIDSESSION\n");
//		fputs($sock, "nVERSIONCOMMANDS\n");
//		var_dump($this->_clam_read($sock));
		fputs($sock, "nVERSION\n");
		$clam_version = $this->_clam_read($sock);

		// send file to clamd as inline stream
		fputs($sock, "nINSTREAM\n");
		rewind($txn['fd']);
		while(!feof($txn['fd'])) {
			// read chunks and pass them to clamd
			$buf = fread($txn['fd'], 8192);
			fwrite($sock, pack('N', strlen($buf)).$buf);
		}
		// send a NULL chunk ("end of data")
		fwrite($sock, pack('N', 0));

		$res = $this->_clam_read($sock); // answer from clamd
		fputs($sock, "nEND\n");
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
			fputs($txn['fd'], \Daemon\PMaild\MTA\Mail::header('X-AntiVirus-ClamAV', 'clean; '.$clam_version.' on '.date(DATE_RFC2822)));
			stream_copy_to_stream($out, $txn['fd']);
			fclose($out);
			$txn['clam'] = false;
			return NULL;
		}

		$virus=str_replace(' FOUND','',$res);
		if ($virus != $res) {
			$txn['clam'] = $virus;
			syslog(LOG_NOTICE, 'pinetd clamav found virus '.$virus);
			return '500 5.7.1 Virus '.$virus.' found in your mail, refusing mail...';
		}
		return '400 4.0.0 Antivirus unknown answer: '.$res;
	}
}

