<?php

namespace Daemon\PMaild\MTA;

use pinetd\Logger;
use pinetd\SQL;

class MailTarget {
	protected $target;
	protected $from;
	protected $localConfig;
	protected $sql;
	protected $IPC;
	protected $login = NULL;

	function __construct($target, $from, $localConfig, $IPC) {
		// transmit mail to target
		$this->target = $target;
		$this->from = $from;
		$this->localConfig = $localConfig;
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		$this->IPC = $IPC;
	}

	public function setLogin($login) {
		$this->login = $login;
	}

	public function makeUniq($path, $domain=null, $account=null) {
		$path = $this->localConfig['Mails']['Path'].'/'.$path;
		if ($path[0] != '/') $path = PINETD_ROOT . '/' . $path; // make it absolute
		if (!is_null($domain)) {
			$id = $domain;
			$id = str_pad($id, 10, '0', STR_PAD_LEFT);
			$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		}
		if (!is_null($account)) {
			$id = $account;
			$id = str_pad($id, 4, '0', STR_PAD_LEFT);
			$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		}
		if (!is_dir($path)) mkdir($path, 0755, true);
		$path .= '/'.microtime(true).'.'.gencode(5).getmypid().'.'.$this->localConfig['Name']['_'];
		return $path;
	}

	function runProtections(&$txn) {
		$domain = $this->target['domainbean'];
		if ($domain->antivirus) {
			$class = relativeclass($this, 'MailFilter\\AntiVirus');
			$antivirus = new $class($domain->antivirus, $domain, $this->localConfig);
			$res = $antivirus->process($txn);
			if (!is_null($res)) return $res;
		}
		if (($domain->antispam) && (is_null($this->login))) {
			$class = relativeclass($this, 'MailFilter\\AntiSpam');
			$antispam = new $class($domain->antispam, $domain, $this->localConfig);
			$res = $antispam->process($txn);
			if (!is_null($res)) return $res;
		}
	}
		
	function processLocal(&$txn) {
		$res = $this->runProtections($txn);
		if (!is_null($res)) return $res;

		$domain = $this->target['domainbean'];
		$domain->last_recv = gmdate('Y-m-d H:i:s');
		$domain->commit();

		// invoke DAOs
		$DAO_accounts = $this->sql->DAO('z'.$this->target['domainid'].'_accounts', 'id');
		$DAO_mails = $this->sql->DAO('z'.$this->target['domainid'].'_mails', 'mailid');

		$DAO_filter = $this->sql->DAO('z'.$this->target['domainid'].'_filter', 'id');
		$DAO_filter_cond = $this->sql->DAO('z'.$this->target['domainid'].'_filter_cond', 'id');
		$DAO_filter_act = $this->sql->DAO('z'.$this->target['domainid'].'_filter_act', 'id');

		// check for redirect/forward
		$account = $DAO_accounts[$this->target['target']];
		if (!is_null($account->forward)) {
			// forward, but still continue processing
			$this->processRemote($txn, $account->forward);
		}

		if (!is_null($account->redirect)) {
			return $this->processRemote($txn, $account->redirect);
		}

		// need to store mail, index headers, etc
		$store = $this->makeUniq('domains', $this->target['domainid'], $this->target['target']);
		$out = fopen($store, 'w+');
		fputs($out, 'Return-Path: <'.$this->from.">\r\n");
		fputs($out, 'X-Original-To: <'.$this->target['mail_user'].'@'.$this->target['mail_domain'].">\r\n");
		fputs($out, 'Delivered-To: <'.$this->target['target_mail'].">\r\n");
		rewind($txn['fd']);
		stream_copy_to_stream($txn['fd'], $out);
		rewind($out);
		$headers = array();
		$last = '';
		while(!feof($out)) {
			$lin = rtrim(fgets($out));
			if ($lin === '') break; // end of headers
			$pos = false;
			if ($lin[0] != "\t")
				$pos = strpos($lin, ':');
			if ($pos !== false) {
				$last = &$headers[];
				$last['header'] = substr($lin, 0, $pos);
				$last = &$last['value'];
				$lin = substr($lin, $pos+1);
			}
			$last .= ' '.ltrim($lin);
		}
		foreach($headers as &$last) $last['value'] = ltrim($last['value']);
		unset($last);
		fclose($out);
		$size = filesize($store); // final stored size
		$quick_headers = array();
		foreach($headers as $h) $quick_headers[trim(strtolower($h['header']))] = trim($h['value']);

		// get root folder for this user
		$folder = 0; // root folder, for all accounts
		$flags = 'recent';

		// manage filters
//		$list = $DAO_filters->loadByField(array('userid' => $this->target['target']), array('order' => 'DESC'));
		foreach($DAO_filter->loadByField(array('userid' => $this->target['target'])) as $rule) {
			$match = true;
			foreach($DAO_filter_cond->loadByField(array('filterid' => $rule->id), array('priority' => 'DESC')) as $cond) {
				switch($cond->source) {
					case 'header':
						$arg = strtolower($cond->arg1);
						if (!isset($quick_headers[$arg])) {
							$match = false;
							break;
						}
						$value = $quick_headers[$arg];
						break;
				}
				if (!$match) break;
				switch($cond->type) {
					case 'exact':
						if ($value != $cond->arg2)
							$match = false;
						break;
					case 'contains':
						if (strpos($value, $cond->arg2) === false)
							$match = false;
						break;
					case 'preg':
						if (!preg_match($cond->arg2, $value))
							$match = false;
						break;
				}
				if (!$match) break;
			}
			if (!$match) continue;

			foreach($DAO_filter_act->loadByField(array('filterid' => $rule->id)) as $act) {
				switch($act->action) {
					case 'move':
						$folder = $act->arg1;
						break;
//					case 'drop':
//						$folder = -1;
//						break;
					case 'flags':
						$flags = $act->arg1;
						break;
				}
			}
		}

		// store mail
		$insert = array(
			'folder' => $folder,
			'userid' => $this->target['target'],
			'size' => $size,
			'uniqname' => basename($store),
			'flags' => $flags,
		);
		$DAO_mails->insertValues($insert);
		$new = $DAO_mails->loadLast();
		$newid = $new->mailid;

		// at this point, the mail is successfully received ! Yatta!
		$this->IPC->broadcast('PMaild::Activity_'.$this->target['domainid'].'_'.$this->target['target'].'_'.$folder, array($newid, 'EXISTS'));

		// Extra: update mail_count and mail_quota
		try {
			$this->sql->query('UPDATE `z'.$this->target['domainid'].'_accounts` AS a SET `mail_count` = (SELECT COUNT(1) FROM `z'.$this->target['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->target['target']).'\'');
			$this->sql->query('UPDATE `z'.$this->target['domainid'].'_accounts` AS a SET `mail_quota` = (SELECT SUM(b.`size`) FROM `z'.$this->target['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->target['target']).'\'');
		} catch(Exception $e) {
			// ignore it
		}
	}

	function processRemote(&$txn, $real_target = null) {
		if (is_null($real_target))
			$real_target = $this->target['target'];

		$res = $this->runProtections($txn);
		if (!is_null($res)) return $res;

		// check/handle multiple targets
		$tmp = explode(',', $real_target);
		if (count($tmp) > 1) {
			$error = null;
			foreach($tmp as $addr) {
				$x = $this->processRemote($txn, $addr);
				if (is_null($error) && (!is_null($x))) $error = $x;
			}
			return $error;
		}

		// store mail & queue
		$store = $this->makeUniq('mailqueue');
		// store file if needed
		if (isset($txn['mailstore_'.$this->target['mail']])) {
			link($txn['mailstore_'.$this->target['mail']], $store);
		} else {
			$out = fopen($store, 'w');
			if (isset($this->target['extra_headers'])) {
				foreach($this->target['extra_headers'] as $h)
					fputs($out, Mail::header($h[0], $h[1]));
			}
			fputs($out, Mail::header('Received', '(PMaild '.getmypid().' invoked for remote email '.$this->target['mail'].'); '.date(DATE_RFC2822)));
			rewind($txn['fd']);
			stream_copy_to_stream($txn['fd'], $out);
			fclose($out);
			$txn['mailstore_'.$this->target['mail']] = $store;
		}

		$insert = array(
			'mlid' => basename($store),
			'to' => $real_target,
			'queued' => $this->sql->now(),
		);
		if ($this->from !== '') $insert['from'] = $this->from;
		if (isset($this->target['tracker'])) $insert['tracker'] = $this->target['tracker'];
		$DAO = $this->sql->DAO('mailqueue', array('mlid', 'to'));
		if ($DAO->insertValues($insert)) return null;
		@unlink($store);
		Logger::log(Logger::LOG_ERR, $this->sql->error);
		return '400 4.0.0 Database error while queueing item';
	}

	function processList(&$txn) {
		$res = $this->runProtections($txn);
		if (!is_null($res)) return $res;

		if ($this->target['mode'] != 'message') return '400 4.5.0 This target is not implemented yet';

		// get targets list
		$DAO_lists_members = $this->sql->DAO('z'.$this->target['domainid'].'_lists_members', 'id');
		$DAO = $this->sql->DAO('mailqueue', array('mlid', 'to'));

		$list = $DAO_lists_members->loadByField(array('list_id' => $this->target['target'], 'status' => 'valid'));
		if (!$list) return NULL; // success (no target => nothing to do => we managed to do nothing => success)

		$got_first = false;
		foreach($list as $target) {
			$store = $this->makeUniq('mailqueue');
			if ($got_first === false) {
				$store = $this->makeUniq('mailqueue');
				$got_first = $store;
				$out = fopen($store, 'w');
				if (isset($this->target['extra_headers'])) {
					foreach($this->target['extra_headers'] as $h)
						fputs($out, Mail::header($h[0], $h[1]));
				}
				if ($this->from !== '') fputs($out, Mail::header('X-Original-From', $this->from));
				fputs($out, Mail::header('Received', '(PMaild '.getmypid().' invoked for list '.$this->target['target'].'); '.date(DATE_RFC2822)));
				rewind($txn['fd']);
				stream_copy_to_stream($txn['fd'], $out);
				fclose($out);
			} else {
				$store = $this->makeUniq('mailqueue');
				link($got_first, $store);
			}

			// drop "from" as it could be used to probe mailing list members, and we don't want to handle bounces
			$insert = array(
				'mlid' => basename($store),
				'to' => $target->email,
				'queued' => $this->sql->now(),
			);
			if ($DAO->insertValues($insert)) continue;
			// failed?
			if ($store == $got_first) $got_first = false;
			@unlink($store);
			Logger::log(Logger::LOG_ERR, $this->sql->error);
		}

		if ($got_first === false) { // all failed
			return '400 4.0.0 Failed to transmit mail to list';
		}
		return NULL;
	}

	function processRedirect(&$txn) {
		return $this->processRemote($txn);
	}

	function httpAnswer($str) {
		if ($str[0]=='2') return null;
		return $str;
	}

	function handleHTTPAPI(&$txn, $call) {
		if (is_string($call)) return $this->httpAnswer($call);
		if (!is_array($call)) {
			if (isset($this->target['on_error'])) {
				$msg = 'While calling '.$url.":\nObject is not a string or an array\n\n".print_r($call, true);
				mail($this->target['on_error'], 'Error at '.$param['to'], $msg, 'From: "Mail Script Caller" <nobody@example.com>');
			}
			return '400 4.0.0 Problem with received object';
		}
		try {
			switch($call['action']) {
				case 'redirect':
					$this->target['type'] = 'remote';
					$this->target['target'] = $call['target'];
					if (isset($call['headers'])) {
						foreach($call['headers'] as $h)
							$this->target['extra_headers'][] = $h;
					}
					if (isset($call['tracker'])) {
						$this->target['tracker'] = $call['tracker'];
						$this->from = ''; // tracker => do not use "from" anymore?
					}
					return $this->process($txn); // reinject mail into system
				default:
					throw new Exception('Unknown call action');
			}
		} catch(Exception $e) {
			if (isset($this->target['on_error'])) {
				$msg = 'While calling '.$url.":\n".$e->getMessage()."\n\n".print_r($call, true);
				mail($this->target['on_error'], 'Error at '.$param['to'], $msg, 'From: "Mail Script Caller" <nobody@example.com>');
			}
			return '400 4.0.0 Problem with received object';
		}
	}

	// Forward email to an HTTP addr
	function processHttp(&$txn) {
		$url = $this->target['target'];
		
		if ($url[0] == '!') {
			$res = $this->runProtections($txn);
			if (!is_null($res)) return $res;
			$url = substr($url, 1);
		}

		$c = '?';
		if (strpos($url, '?') !== false) $c = '&';
		$param = array(
//			'helo' => $txn['helo'],
			'remote_ip' => $txn['peer'][0],
			'remote_host' => $txn['peer'][2],
			'from' => $this->from,
			'to' => $this->target['mail'],
			'api_version' => '2.0',
		);
		foreach($txn as $var=>$val) {
			if ($var == 'peer') continue;
			if ($var == 'fd') continue;
			if ($var == 'parent') continue;
			if (!is_string($val)) {
				$var = '_' . $var;
				$val = serialize($val);
			}
			$param[$var] = $val; // provide helo, clam and spamassassin infos (and other if available)
		}
		foreach($param as $var => $val) {
			$url .= $c . $var . '=' . urlencode($val);
			$c = '&';
		}
		fseek($txn['fd'], 0, SEEK_END);
		$len = ftell($txn['fd']);
		rewind($txn['fd']);
		$ch = \curl_init($url); // HTTP
		\curl_setopt($ch, CURLOPT_PUT, true);
		\curl_setopt($ch, CURLOPT_INFILE, $txn['fd']);
		\curl_setopt($ch, CURLOPT_INFILESIZE, $len);
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Ipc: MAIL'));
		$res = \curl_exec($ch);
		if (substr($res, 0, 3) == '250') return null;
		if (!preg_match("/^[0-9]{3} [^\n]+\$/", $res)) {
			// check if that is an API call...
			if (substr($res, 0, 8) == 'PMAPI2.0') {
				$call = unserialize(substr($res, 8));
				if (!$call) {
					if (isset($this->target['on_error'])) {
						$msg = 'While calling '.$url.":\n\n".$res;
						mail($this->target['on_error'], 'Error at '.$param['to'], $msg, 'From: "Mail Script Caller" <nobody@example.com>');
					}
					return '450 4.0.0 Remote error while transferring mail, please retry later';
				}
				return $this->handleHTTPAPI($txn, $call);
			}
			if (isset($this->target['on_error'])) {
				$msg = 'While calling '.$url.":\n\n".$res;
				mail($this->target['on_error'], 'Error at '.$param['to'], $msg, 'From: "Mail Script Caller" <nobody@example.com>');
			}
			return '450 4.0.0 Remote error while transferring mail, please retry later';
		}
		return $this->httpAnswer($res);
	}

	function process(&$txn) {
		$func = 'process'.ucfirst($this->target['type']);
		return $this->$func($txn);
	}
}


