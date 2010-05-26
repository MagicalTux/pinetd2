<?php

// http://www.isi.edu/in-notes/rfc2683.txt (IMAP4 Implementation Recommendations)
// http://www.faqs.org/rfcs/rfc3501.html (IMAP4rev1)
// http://www.faqs.org/rfcs/rfc2045.html (Multipurpose Internet Mail Extensions (MIME) Part One)


namespace Daemon\PMaild;

use pinetd\SQL;
use pinetd\SQL\Expr;

class Quoted {
	private $value;

	public function __construct($value) {
		$this->value = $value;
	}

	public function __toString() {
		if (is_null($this->value)) return 'NIL';
		return '"'.addcslashes($this->value, '"').'"';
	}
}

class ArrayList {
	private $value;

	public function __construct($value) {
		$this->value = $value;
	}

	public function getValue() {
		return $this->value;
	}
}

class IMAP4_Client extends \pinetd\TCP\Client {
	protected $login = null;
	protected $info = null;
	protected $loggedin = false;
	protected $sql;
	protected $localConfig;
	protected $queryId = null;
	protected $selectedFolder = null;
	protected $uidmap = array();
	protected $reverseMap = array();
	protected $uidmap_next = 0;
	protected $idle_mode = false;
	protected $idle_queue = array();
	protected $idle_event = NULL;

	function __construct($fd, $peer, $parent, $protocol) {
		parent::__construct($fd, $peer, $parent, $protocol);
		$this->setMsgEnd("\r\n");
	}

	function welcomeUser() { // nothing to do
		return true;
	}

	protected function parseFetchParam($param, $kw = NULL) {
		// support for macros
		switch($kw) {
			case 'fetch':
				switch(strtoupper($param)) {
					case 'ALL': $param = '(FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)'; break;
					case 'FAST': $param = '(FLAGS INTERNALDATE RFC822.SIZE)'; break;
					case 'FULL': $param = '(FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)'; break;
				}
				break;
		}
		$param = rtrim($param);
		$result = array();
		$string = null;
		$reference = &$result;
		$len = strlen($param);
		$level = 0;
		$in_string = false;
		$ref = array(0 => &$result);

		for($i=0; $i<$len;$i++) {
			$c = $param[$i];
			if ($c == '"') {
				if (!$in_string) {
					if (!is_null($string)) throw new \Exception('Parse error');
					$in_string = true;
					$string = '';
					continue;
				}
				$reference[] = $string;
				$in_string = false;
				$string = null;
				continue;
			}
			if ($in_string) {
				$string .= $c;
				continue;
			}
			if ($c == '(') {
				$level++;
				$array = array();
				$ref[$level] = &$array;
				$reference[] = &$array;
				$reference = &$array;
				unset($array);
				continue;
			}
			if ($c == '[') {
				$level++;
				if (is_null($string)) throw new Exception('parse error');
				$array = array();
				$ref[$level] = &$array;
				$reference[$string] = &$array;
				$reference = &$array;
				unset($array);
				$string = null;
				continue;
			}
			if (($c == ')') || ($c == ']')) {
				$level--;
				if (!is_null($string)) $reference[] = $string;
				$string = null;
				$reference = &$ref[$level];
				continue;
			}
			if ($c == ' ') {
				if (is_null($string)) continue;
				$reference[] = $string;
				$string = null;
				continue;
			}
			if ($c == '{') { // string litteral (pending data, see RFC 3501 page 15)
				if (!is_null($string)) throw new Exception('parse error');
				$string = '';
				continue;
			}
			if ($c == '}') {
				if (is_null($string)) throw new Exception('parse error');
				if (!is_numeric($string)) throw new Exception('parse error');
				if ($i != ($len - 1)) throw new Exception('parse error'); // not at end of string

				$len = (int)$string;
				parent::sendMsg('+ Please continue'); // avoid tag
				$reference[] = $this->readTmpFd($len);
				$param = rtrim($this->readLine());
				$len = strlen($param);
				$i = -1; // will become 0 at next loop
				continue;
			}
			$string .= $c;
		}
		if (!is_null($string)) $result[] = $string;
		if (is_array($result[0])) $result = $result[0];
		unset($result['parent']);
		return $result;
	}

	function imapParam($str, $label=null) {
		if (is_null($str)) return 'NIL';
		if (is_array($str)) {
			$res = '';
			foreach($str as $lbl => $var) {
				$cur = $this->imapParam($var, $lbl);
				$res.=($res == ''?'':' ').$cur;
			}
			if (is_string($label)) {
				if ($res == '""')
					$res = '';
				return $label.'['.$res.']';
			}
			return '('.$res.')';
		}
		if ((is_object($str)) && ($str instanceof Quoted)) {
			return (string)$str;
		}
		if ((is_object($str)) && ($str instanceof ArrayList)) {
			$res = '';
			$val = $str->getValue();
			foreach($val as $var) {
				$res .= $this->imapParam($var);
			}
			if (is_string($label)) {
				return $label.'['.$res.']';
			}
			return $res;
		}
		if ($str === '') return '""';
		if (strpos($str, "\n") !== false) {
			return '{'.strlen($str).'}'."\r\n".$str; // TODO: is this linebreak ok?
		}
		$add = addcslashes($str, '"\'');
		if (($add == $str) && ($str != 'NIL') && (strpos($str, ' ') === false)) return $str;
		return '"'.$add.'"';
	}

	function sendBanner() {
		$this->sendMsg('OK '.$this->IPC->getName().' IMAP4rev1 2001.305/pMaild on '.date(DATE_RFC2822));
		$this->localConfig = $this->IPC->getLocalConfig();
		return true;
	}
	protected function parseLine($lin) {
		$lin = rtrim($lin); // strip potential \r and \n
		if ($this->idle_mode) {
			if (strtolower($lin) != 'done') return;
			$this->sendMsg('OK IDLE terminated');
			$this->idle_mode = false;
		}
		$match = array();
		$res = preg_match_all('/([^" ]+)|("(([^\\\\"]|(\\\\")|(\\\\\\\\))*)")/', $lin, $match);
		$argv = array();
		foreach($match[0] as $idx=>$arg) {
			if (($arg[0] == '"') && (substr($arg, -1) == '"')) {
				$argv[] = preg_replace('/\\\\(.)/', '\\1', $match[3][$idx]);
				continue;
			}
			$argv[] = $arg;
		}
		$this->queryId = array_shift($argv);
		$cmd = '_cmd_'.strtolower($argv[0]);
		if (!method_exists($this, $cmd)) $cmd = '_cmd_default';
		$res = $this->$cmd($argv, $lin);
		$this->queryId = null;
		return $res;
	}

	public function sendMsg($msg, $id=null) {
		if (is_null($id)) $id = $this->queryId;
		if (is_null($id)) $id = '*';
		return parent::sendMsg($id.' '.$msg);
	}

	protected function updateUidMap() {
		// compute uidmap and uidnext
		$this->debug('Updating UID map');
		$this->uidmap = array();
		$this->reverseMap = array();
		$pos = $this->selectedFolder;
		$req = 'SELECT `mailid` FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' ';
		$req.= 'AND `folder`=\''.$this->sql->escape_string($pos).'\' ';
		$req.= 'ORDER BY `mailid` ASC';
		$res = $this->sql->query($req);
		$id = 1;
		$uidnext = 1;
		while($row = $res->fetch_assoc()) {
			$this->uidmap[$id] = $row['mailid'];
			$this->reverseMap[$row['mailid']] = $id++;
			$uidnext = $row['mailid'] + 1;
		}
		$this->uidmap_next = $id;
		return $uidnext;
	}

	protected function allocateQuickId($uid) {
		$id = $this->uidmap_next++;
		$this->uidmap[$id] = $uid;
		$this->reverseMap[$uid] = $id;
		return $id;
	}

	function shutdown() {
		$this->sendMsg('BYE IMAP4 server is shutting down, please try again later', '*');
	}

	protected function identify($pass) { // login in $this->login
		$class = relativeclass($this, 'MTA\\Auth');
		$auth = new $class($this->localConfig);
		$this->loggedin = $auth->login($this->login, $pass, 'imap4');
		if (!$this->loggedin) return false;
		$this->login = $auth->getLogin();
		$info = $auth->getInfo();
		$this->info = $info;
		// link to MySQL
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		return true;
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

	function _cmd_default($argv, $lin) {
		$this->sendMsg('BAD Unknown command');
		var_dump($argv, $lin);
	}

	function _cmd_noop() {
		$this->sendMsg('OK NOOP completed');
	}

	function _cmd_check() {
		// RFC 3501 6.4.1. CHECK Command.
		// check is equivalent to "NOOP" if not needed
		$this->sendMsg('OK CHECK completed');
	}

	function _cmd_capability() {
		$secure = true;
		if ($this->protocol == 'tcp') $secure=false;
		$this->sendMsg('CAPABILITY IMAP4REV1 '.($secure?'':'STARTTLS ').'X-NETSCAPE NAMESPACE MAILBOX-REFERRALS SCAN SORT THREAD=REFERENCES THREAD=ORDEREDSUBJECT MULTIAPPEND LOGIN-REFERRALS IDLE AUTH='.($secure?'LOGIN':'LOGINDISABLED'), '*');
		$this->sendMsg('OK CAPABILITY completed');
	}

	function _cmd_logout() {
		$this->sendMsg('BYE '.$this->IPC->getName().' IMAP4rev1 server says bye!', '*');
		$this->sendMsg('OK LOGOUT completed');
		$this->close();

		if ($this->loggedin) {
			// Extra: update mail_count and mail_quota
			try {
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_count` = (SELECT COUNT(1) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_quota` = (SELECT SUM(b.`size`) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
			} catch(Exception $e) {
				// ignore it
			}
		}
	}

	function _cmd_starttls() {
		if (!$this->IPC->hasTLS()) {
			$this->sendMsg('NO SSL not available');
			return;
		}
		if ($this->protocol != 'tcp') {
			$this->sendMsg('BAD STARTTLS only available in PLAIN mode. An encryption mode is already enabled');
			return;
		}
		$this->sendMsg('OK STARTTLS completed');
		// TODO: this call will lock, need a way to avoid from doing it without Fork
		if (!stream_socket_enable_crypto($this->fd, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			$this->sendMsg('BYE TLS negociation failed!', '*');
			$this->close();
		}
		$this->debug('SSL mode enabled');
		$this->protocol = 'tls';
	}

	function _cmd_login($argv) {
		// X LOGIN login password
		if ($this->loggedin) return $this->sendMsg('BAD Already logged in');
		if ($this->protocol == 'tcp') return $this->sendMsg('BAD Need SSL before logging in');
		$this->login = $argv[1];
		$pass = $argv[2];
		if (!$this->identify($pass)) {
			$this->sendMsg('NO Login or password are invalid.');
			return;
		}
		$this->sendMsg('OK LOGIN completed');
	}

	function _cmd_authenticate($argv) {
		if ($this->loggedin) return $this->sendMsg('BAD Already logged in');
		if ($this->protocol == 'tcp') return $this->sendMsg('BAD Need SSL before logging in');
		if (strtoupper($argv[1]) != 'LOGIN') {
			$this->sendMsg('BAD Unsupported auth method');
			return;
		}
		parent::sendMsg('+ '.base64_encode('User Name')); // avoid tag
		$res = $this->readLine();
		if ($res == '*') return $this->sendMsg('BAD AUTHENTICATE cancelled');
		$this->login = base64_decode($res);
		$this->debug('Login: '.$this->login);

		parent::sendMsg('+ '.base64_encode('Password')); // avoid tag
		$res = $this->readLine();
		if ($res == '*') return $this->sendMsg('BAD AUTHENTICATE cancelled');
		$pass = base64_decode($res);
		$this->debug('Pass: '.$pass);

		if(!$this->identify($pass)) {
			$this->sendMsg('NO AUTHENTICATE failed; login or password are invalid');
			return;
		}
		$this->sendMsg('OK AUTHENTICATE succeed');
	}

	function _cmd_namespace() {
		// * NAMESPACE (("" "/")("#mhinbox" NIL)("#mh/" "/")) (("~" "/")) (("#shared/" "/")("#ftp/" "/")("#news." ".")("#public/" "/"))
		// TODO: find some documentation and adapt this function
		// Documentation for namespaces : RFC2342
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$this->sendMsg('NAMESPACE (("" "/")) NIL NIL', '*');
		$this->sendMsg('OK NAMESPACE completed');
	}

	function _cmd_lsub($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$namespace = $argv[1];
		$param = $argv[2];
		if ($namespace == '') $namespace = '/';
		if ($namespace != '/') {
			$this->sendMsg('NO Unknown namespace');
			return;
		}
		// TODO: Find doc and fix that according to correct process
		$this->sendMsg('LSUB () "/" INBOX', '*');
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$list = $DAO_folders->loadByField(array('account'=>$this->info['account']->id, 'subscribed' => 1));
		// cache list
		$cache = array(
			0 => array(
				'id' => 0,
				'name' => 'INBOX',
				'parent' => null,
			),
		);
		foreach($list as $info) {
			$info['name'] = mb_convert_encoding($info['name'], 'UTF7-IMAP', 'UTF-8'); // convert UTF-8 -> modified UTF-7
			$cache[$info['id']] = $info;
		}
		// list folders in imap server
		foreach($list as $info) {
			$info = $cache[$info['id']];
			$name = $info['name'];
			$parent = $info['parent'];
			while(!is_null($parent)) {
				$info = $cache[$parent];
				$name = $info['name'].'/'.$name;
				$parent = $info['parent'];
			}
			$flags = '';
			if ($info['flags'] != '')
				foreach(explode(',', $info['flags']) as $f) $flags.=($flags==''?'':',').'\\'.ucfirst($f);
			$this->sendMsg('LSUB ('.$flags.') "/" '.$this->maybeQuote($name), '*');
		}
		$this->sendMsg('OK LSUB completed');
	}

	function imapWildcard($pattern, $string) {
		$pattern = preg_quote($pattern, '#');
		$pattern = str_replace('\\*', '.*', $pattern);
		$pattern = str_replace('%', '[^/]*', $pattern);
		return preg_match('#^'.$pattern.'$#', $string);
	}

	function _cmd_list($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$reference = $argv[1];
		$param = $argv[2];
		if ($param == '') {
			$this->sendMsg('LIST (\NoSelect) "/" ""', '*');
			$this->sendMsg('OK LIST completed');
			return;
		}
		if ($reference == '') $reference = '/';
		$name = $param;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$parent = null;
		if ($reference != '/') {
			foreach(explode('/', $reference) as $ref) {
				if ($ref === '') continue;
				if ((is_null($parent)) && ($ref == 'INBOX')) {
					$parent = 0;
					continue;
				}
				$cond = array('account' => $this->info['account']->id, 'parent' => $parent, 'name' => $ref);
				$result = $DAO_folders->loadByField($cond);
				if (!$result) {
					$this->sendMsg('NO folder not found');
					return;
				}
				$parent = $result[0]->parent;
			}
		}
		$list = array();
		$fetch = array($parent);
		if (is_null($parent) && (fnmatch($param, 'INBOX'))) {
			$list[0] = array('name' => 'INBOX', 'children' => 0, 'parent' => null);
			$fetch[] = 0;
		}
		$cond = array('account' => $this->info['account']->id, 'parent' => $parent);
		// load whole tree, makes stuff easier - list should be recursive unless '%' is provided
		// start at parent
		$done = array();
		while($fetch) {
			$id = array_pop($fetch);
			if (isset($done[$id])) continue; // infinite loop
			$done[$id] = true;
			$cond['parent'] = $id;
			$result = $DAO_folders->loadByField($cond);
			foreach($result as $folder) {
				$folder = $folder->getProperties();
				if (is_null($folder['parent'])) $folder['parent'] = -1;

				if (isset($list[$folder['parent']])) {
					$folder['name'] = $list[$folder['parent']]['name'] . '/' . $folder['name'];
					$list[$folder['parent']]['children']++;
				}
				$fetch[] = $folder['id'];
				$folder['children'] = 0;
				$list[$folder['id']] = $folder;
			}
		}
		foreach($list as $res) {
			if (!$this->imapWildcard($param, $res['name'])) continue;
			$name = mb_convert_encoding($res['name'], 'UTF-8', 'UTF7-IMAP');
			$flags = array();
			if ($res['flags'] != '')
				foreach(explode(',', $res['flags']) as $f) $flags[]='\\'.ucfirst($f);

			if ($res['children'] == 0) {
				$flags[] = '\\HasNoChildren';
			} else {
				$flags[] = '\\HasChildren';
			}

			$this->sendMsg('LIST ('.implode(',',$flags).') "'.$reference.'" '.$this->maybeQuote($name), '*');
		}
		$this->sendMsg('OK LIST completed');
	}

	function maybeQuote($name) {
		if ($name === '') return '""';
		if ((strpos($name, ' ') === false) && (addslashes($name) == $name)) return $name;
		return '"'.addslashes($name).'"';
	}

	protected function lookupFolder($box) {
		$box = mb_convert_encoding($box, 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				return NULL;
			}
			$pos = $result[0]->id;
		}
		$flags = array_flip(explode(',', $result[0]->flags));
		return array('id' => $pos, 'flags' => $flags);
	}

	function _cmd_select($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		if (count($argv) != 2) {
			$this->sendMsg('BAD Please provide only one parameter to SELECT');
			return;
		}
		$box = $this->lookupFolder($argv[1]);
		if (is_null($box)) {
			$this->sendMsg('NO No such mailbox');
			return;
		}
		if (isset($box['flags']['noselect'])) return $this->sendMsg('NO This folder has \\Noselect flag');
		$this->selectedFolder = $box['id'];
		// TODO: find a way to do this without SQL code?
		$req = 'SELECT `flags`, COUNT(1) AS num FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' AND `folder` = \''.$this->sql->escape_string($this->selectedFolder).'\' GROUP BY `flags`';
		$res = $this->sql->query($req);
		$total = 0;
		$recent = 0;
		$unseen = 0;
		while($row = $res->fetch_assoc()) {
			$flags = array_flip(explode(',', $row['flags']));
			if (isset($flags['recent'])) $recent+=$row['num'];
			$total += $row['num'];
		}
		$uidnext = $this->updateUidMap();

		if ($recent > 0) {
			// got a recent mail, fetch its ID
			$req = 'SELECT `mailid` FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' ';
			$req.= 'AND `folder`=\''.$this->sql->escape_string($pos).'\' AND FIND_IN_SET(\'recent\',`flags`)>0 ';
			$req.= 'ORDER BY `mailid` ASC LIMIT 1';
			$res = $this->sql->query($req);
			if ($res) $res = $res->fetch_assoc();
			if ($res) {
				$unseen = $res['mailid'];
				// TODO: clear "recent" flag where mailid <= unseen
				$unseen = $this->reverseMap[$unseen];
//				$unseen = array_search($unseen, $this->uidmap);
			}
		}


		// send response
		$this->sendMsg($total.' EXISTS', '*');
		$this->sendMsg($recent.' RECENT', '*');
		$this->sendMsg('OK [UIDVALIDITY '.$this->info['account']->id.'] UIDs valid', '*');
		$this->sendMsg('OK [UIDNEXT '.$uidnext.'] Predicted next UID', '*');
		$this->sendMsg('FLAGS (\Answered \Flagged \Deleted \Seen \Draft)', '*');
		$this->sendMsg('OK [PERMANENTFLAGS (\* \Answered \Flagged \Deleted \Draft \Seen)] Permanent flags', '*');
		if ($unseen) $this->sendMsg('OK [UNSEEN '.$unseen.'] Message '.$unseen.' is first recent', '*');
		if ($argv[0] == 'EXAMINE') {
			$this->sendMsg('OK [READ-ONLY] EXAMINE completed');
			return;
		}
		$this->sendMsg('OK [READ-WRITE] SELECT completed');

		$this->idleFolderChanged();
	}

	function _cmd_examine($argv) {
		$argv[0] = 'EXAMINE';
		return $this->_cmd_select($argv); // examine is the same, but read-only
	}

	function _cmd_create($argv) {
		$box = mb_convert_encoding($argv[1], 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$newbox = array_pop($box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				$this->sendMsg('NO No such mailbox');
				return;
			}
			$pos = $result[0]->id;
		}
		if (is_null($pos) && ($newbox == 'INBOX')) {
			$this->sendMsg('NO Do not create INBOX, it already exists, damnit!');
			return;
		}
		$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $newbox, 'parent' => $pos));
		if ($result) {
			$result = $result[0];
			$flags = array_flip(explode(',', $result->flags));
			if (isset($flags['noselect'])) {
				$result->flags = ''; // clear flags
				$result->commit();
				$this->sendMsg('OK CREATE completed');
				return;
			}
			$this->sendMsg('NO Already exists');
			return;
		}
		$insert = array(
			'account' => $this->info['account']->id,
			'name' => $newbox,
			'parent' => $pos,
		);
		if (!$DAO_folders->insertValues($insert)) {
			$this->sendMsg('NO Unknown error');
			return;
		}
		$this->sendMsg('OK CREATE completed');
	}

	function _cmd_delete($argv) {
		$box = mb_convert_encoding($argv[1], 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				$this->sendMsg('NO No such mailbox');
				return;
			}
			$pos = $result[0]->id;
		}
		if ($pos === 0) {
			// RFC says deleting INBOX is an error (RFC3501, 6.3.4)
			$this->sendMsg('NO Do not delete INBOX, where will I be able to put your mails?!');
			return;
		}
		if (is_null($pos)) {
			$this->sendMsg('NO hey man! Do not delete root, would you?');
			return;
		}
		// delete box content
		$this->sql->query('DELETE mm, mmh, m FROM `z'.$this->info['domainid'].'_mime` AS mm, `z'.$this->info['domainid'].'_mime_header` AS mmh, `z'.$this->info['domainid'].'_mails` AS m WHERE m.`parent` = \''.$this->sql->escape_string($pos).'\' AND m.`account` = \''.$this->sql->escape_string($this->info['account']->id).'\' AND m.mailid = mm.mailid AND m.mailid = mmh.mailid');
		// check if box has childs
		$res = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'parent' => $pos));
		$result = $result[0]; // from the search loop
		if ($res) {
			$result->flags = 'noselect'; // put noselect flag
			$result->commit();
			$this->sendMsg('OK DELETE completed');
			return;
		}
		$result->delete();
		$this->sendMsg('OK DELETE completed');
	}

	function _cmd_close() {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$DAO_mime = $this->sql->DAO('z'.$this->info['domainid'].'_mime', 'mimeid');
		$DAO_mime_header = $this->sql->DAO('z'.$this->info['domainid'].'_mime_header', 'headerid');
		$result = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'folder' => $this->selectedFolder, new Expr('FIND_IN_SET(\'deleted\',`flags`)>0')));
		
		foreach($result as $mail) {
			$DAO_mime->delete(array('userid' => $this->info['account']->id, 'mailid' => $mail->mailid));
			$DAO_mime_header->delete(array('userid' => $this->info['account']->id, 'mailid' => $mail->mailid));
			@unlink($this->mailPath($mail->uniqname));
			$mail->delete();
		}
		$this->selectedFolder = NULL;
		$this->idleFolderChanged();
		$this->sendMsg('OK CLOSE completed');
	}

	function _cmd_expunge() {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$DAO_mime = $this->sql->DAO('z'.$this->info['domainid'].'_mime', 'mimeid');
		$DAO_mime_header = $this->sql->DAO('z'.$this->info['domainid'].'_mime_header', 'headerid');
		$result = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'folder' => $this->selectedFolder, new Expr('FIND_IN_SET(\'deleted\',`flags`)>0')));
		
		foreach($result as $mail) {
			$DAO_mime->delete(array('userid' => $this->info['account']->id, 'mailid' => $mail->mailid));
			$DAO_mime_header->delete(array('userid' => $this->info['account']->id, 'mailid' => $mail->mailid));
			@unlink($this->mailPath($mail->uniqname));
			$this->sendMsg($this->reverseMap[$mail->mailid].' EXPUNGE', '*');
			$this->IPC->broadcast('PMaild::Activity_'.$this->info['domainid'].'_'.$this->info['account']->id.'_'.$mail->folder, array($mail->mailid, 'EXPUNGE'));
			unset($this->reverseMap[$mail->mailid]);
			$mail->delete();
		}
		$this->sendMsg('OK EXPUNGE completed');
	}

	function fetchMailByUid(array $where, $param) {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		// TODO: implement headers fetch via mail class

		$result = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'folder' => $this->selectedFolder) + $where);
		if (!$result) return false;
		foreach($result as $mail) {
			$class = relativeclass($this, 'Mail');
			$omail = new $class($this->info, $mail, $this->mailPath($mail->uniqname), $this->sql);
			if (!$omail->valid()) {
				$omail->delete();
				continue;
			}

			$uid = $mail->mailid;
			if (!isset($this->reverseMap[$uid])) {
				// we do not know this mail, allocate an id quickly
				$id = $this->allocateQuickId($uid);
			} else {
				$id = $this->reverseMap[$uid];
			}

			$this->sendMsg($id.' FETCH '.$this->fetchParamByMail($omail, $param), '*');
		}
		return true;
	}

	function fetchMailById($id, $param) {
		// not in the current uidmap?
		if (!isset($this->uidmap[$id])) {
			return false;
		}
		$uid = $this->uidmap[$id];
		return $this->fetchMailByUid(array('mailid' => $uid), $param);
	}

	function fetchParamByMail($mail, $param) {
		$res = array();
		foreach($param as $id => $item) {
			if ((is_array($item)) && (is_int($id))) {
				$res[] = $this->fetchParamByMail($mail, $item);
				continue;
			}
			$item_param = null;
			if (!is_int($id)) {
				$item_param = $item;
				$item = $id;
			}
			switch(strtoupper($item)) {
				case 'UID':
					$res[] = 'UID';
					$res[] = $mail->getId();
					break;
				case 'ENVELOPE':
					$res[] = 'ENVELOPE';
					$res[] = $mail->getEnvelope();
					break;
				case 'BODY':
					// TODO: clear "Recent" flag
				case 'BODY.PEEK':
					$res_body = $mail->fetchBody($item_param);
					foreach($res_body as $t => $v) {
						if (is_string($t)) {
							$res[$t] = $v;
							continue;
						}
						$res[] = $v;
					}
					break;
				case 'BODYSTRUCTURE':
					$res[] = 'BODYSTRUCTURE';
					$res[] = $mail->getStructure();
					break;
				case 'RFC822.SIZE': // TODO: determine if we should include headers in size
					$res[] = 'RFC822.SIZE';
					$res[] = $mail->size();
					break;
				case 'FLAGS':
					$f = array();
					if ($mail->flags != '') {
						$flags = explode(',', $mail->flags);
						foreach($flags as $flag) $f[] = '\\'.ucfirst($flag);
					}
					$res[] = 'FLAGS';
					$res[] = $f;
					break;
				case 'INTERNALDATE':
					$res[] = 'INTERNALDATE';
					$res[] = date(DATE_RFC2822, $mail->creationTime());
					break;
				default:
					var_dump($item, $item_param);
					$res[] = strtoupper($item);
					$res[] = NULL;
					break;
			}
		}
		return $this->imapParam($res);
	}

	function _cmd_subscribe($argv) {
		$box = $this->lookupFolder($argv[1]);
		if (is_null($box)) {
			$this->sendMsg('NO Folder not found');
			return;
		}
		if ($box['id'] == 0) {
			$this->sendMsg('NO INBOX cannot be subscribed');
			return;
		}

		// load bean
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$folder = $DAO_folders[$box['id']];
		$folder->subscribed = 1;
		$folder->commit();
		$this->sendMsg('OK SUBSCRIBE completed');
	}

	function _cmd_unsubscribe($argv) {
		$box = $this->lookupFolder($argv[1]);
		if (is_null($box)) {
			$this->sendMsg('NO Folder not found');
			return;
		}
		if ($box['id'] == 0) {
			$this->sendMsg('NO INBOX cannot be unsubscribed');
			return;
		}

		// load bean
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$folder = $DAO_folders[$box['id']];
		$folder->subscribed = 0;
		$folder->commit();
		$this->sendMsg('OK UNSUBSCRIBE completed');
	}
/*
A FETCH 1 (UID ENVELOPE BODY.PEEK[HEADER.FIELDS (Newsgroups Content-MD5 Content-Disposition Content-Language Content-Location Followup-To References)] INTERNALDATE RFC822.SIZE FLAGS)
* 1 FETCH (UID 1170 ENVELOPE ("9 Aug 2005 18:25:47 -0000" "New graal.net Player World Submitted" ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "MagicalTux" "online.fr")) NIL NIL NIL "<20050809182547.3404.qmail@europa13.legende.net>") BODY[HEADER.FIELDS ("NEWSGROUPS" "CONTENT-MD5" "CONTENT-DISPOSITION" "CONTENT-LANGUAGE" "CONTENT-LOCATION" "FOLLOWUP-TO" "REFERENCES")] {2}

 INTERNALDATE " 9-Aug-2005 20:07:37 +0000" RFC822.SIZE 1171 FLAGS (\Seen))
A OK FETCH completed

A FETCH 1 (UID)
* 1 FETCH (UID 1170)

A FETCH 1 (ENVELOPE)
* 1 FETCH (ENVELOPE ("9 Aug 2005 18:25:47 -0000" "New graal.net Player World Submitted" ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "MagicalTux" "online.fr")) NIL NIL NIL "<20050809182547.3404.qmail@europa13.legende.net>"))
A OK FETCH completed

A FETCH 1 BODY.PEEK[HEADER]
* 1 FETCH (BODY[HEADER] {567}
Return-Path: <noreply@graal.net>
Delivered-To: online.fr-MagicalTux@online.fr
Received: (qmail 29038 invoked from network); 9 Aug 2005 20:07:37 -0000
Received: from europa13.legende.net (194.5.30.13)
  by mrelay5-1.free.fr with SMTP; 9 Aug 2005 20:07:37 -0000
Received: (qmail 3405 invoked by uid 99); 9 Aug 2005 18:25:47 -0000
Date: 9 Aug 2005 18:25:47 -0000
Message-ID: <20050809182547.3404.qmail@europa13.legende.net>
To: MagicalTux@online.fr
From: <noreply@graal.net>
Subject: New graal.net Player World Submitted
Content-type: text/plain; charset=

)
A OK FETCH completed

*/
		// read it

	function _cmd_fetch($argv) {
		array_shift($argv); // FETCH
		$id = array_shift($argv); // might be "2:4"

		// parse param
		$param = implode(' ', $argv);
		// ok, let's parse param
		$param = $this->parseFetchParam($param, 'fetch');

		$last = null;
		while(strlen($id) > 0) {
			$pos = strpos($id, ':');
			$pos2 = strpos($id, ',');
			if ($pos === false) $pos = strlen($id);
			if ($pos2 === false) $pos2 = strlen($id);
			if ($pos < $pos2) {
				// got an interval. NB: 1:3:5 is impossible, must be 1:3,5 or something like that
				$start = substr($id, 0, $pos);
				$end = substr($id, $pos+1, $pos2 - $pos - 1);
				$id = substr($id, $pos2+1);
				if ($end == '*') {
					$i = $start;
					while($this->fetchMailById($i++, $param));
					continue;
				}
				for($i=$start; $i <= $end; $i++) {
					$this->fetchMailById($i, $param);
				}
			} else {
				$i = substr($id, 0, $pos2);
				$id = substr($id, $pos2+1);
				$this->fetchMailById($i, $param);
			}
		}
		$this->sendMsg('OK FETCH completed');
	}

//A00008 UID FETCH 1:* (FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE FROM TO CC SUBJECT REFERENCES IN-REPLY-TO MESSAGE-ID MIME-VERSION CONTENT-TYPE X-MAILING-LIST X-LOOP LIST-ID LIST-POST MAILING-LIST ORIGINATOR X-LIST SENDER RETURN-PATH X-BEENTHERE)])
	function _cmd_uid($argv) {
		array_shift($argv); // UID
		$fetch = array_shift($argv); // FETCH
		
		// UID COPY, UID FETCH, UID STORE
		// UID SEARCH
		$func = '_cmd_uid_'.strtolower($fetch);
		if (method_exists($this, $func))
			return $this->$func($argv);

		$this->sendMsg('BAD Unsupported UID command ('.$fetch.')');
		return;
	}

	protected function _cmd_search($argv) {
		array_shift($argv); // "SEARCH"
		$param = implode(' ', $argv);
		$param = $this->parseFetchParam($param);
		if (strtoupper($param[0]) == 'CHARSET') {
			array_shift($param); // CHARSET
			$charset = strtoupper(array_shift($param)); // charset
			if (($charset != 'UTF-8') && ($charset != 'US-ASCII')) {
				$this->sendMsg('NO [BADCHARSET] UTF-8 US-ASCII');
				return;
			}
		}
		var_dump($param);
		$this->sendMsg('OK SEARCH completed');
	}

	protected function _cmd_uid_search($argv) {
		array_unshift($argv, 'SEARCH');
		$this->_cmd_search($argv);
	}

	protected function _cmd_uid_fetch($argv) {
		$id = array_shift($argv); // 1:*

		// parse param
		$param = implode(' ', $argv);
		// ok, let's parse param
		$param = $this->parseFetchParam($param, 'fetch');
		$param[] = 'UID';

		foreach($this->transformRange($id) as $where) {
			$this->fetchMailByUid($where, $param);
		}
		$this->sendMsg('OK FETCH completed');
	}

	protected function storeFlags($where, $mode, $flags) {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');

		$result = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'folder' => $this->selectedFolder)+$where);
		foreach($result as $mail) {
			if ($mail->flags == '') {
				$tmpfl = array();
			} else {
				$tmpfl = explode(',', $mail->flags);
			}
			array_flip($tmpfl);
			switch($mode) {
				case 'set':
					$tmpfl = array();
				case 'add':
					foreach($flags as $f)
						$tmpfl[strtolower(substr($f, 1))] = strtolower(substr($f, 1));
					break;
				case 'sub':
					foreach($flags as $f)
						unset($tmpfl[strtolower(substr($f, 1))]);
					break;
			}
			$mail->flags = implode(',', array_flip($tmpfl));
			$mail->commit();
		}
	}

	protected function transformRange($id) {
		$res = array();

		$last = null;
		while(strlen($id) > 0) {
			$pos = strpos($id, ':');
			$pos2 = strpos($id, ',');
			if ($pos === false) $pos = strlen($id);
			if ($pos2 === false) $pos2 = strlen($id);
			if ($pos < $pos2) {
				// got an interval. NB: 1:3:5 is impossible, must be 1:3,5 or something like that
				$start = substr($id, 0, $pos);
				$end = substr($id, $pos+1, $pos2 - $pos - 1);
				$id = substr($id, $pos2+1);
				if ($end == '*') {
					$where = array(new Expr('`mailid` >= '.$this->sql->quote_escape($start)));
				} else {
					$where = array();
					$where[] = new Expr('`mailid` >= '.$this->sql->quote_escape($start));
					$where[] = new Expr('`mailid` <= '.$this->sql->quote_escape($end));
				}
				$res[] = $where;
			} else {
				$i = substr($id, 0, $pos2);
				$id = substr($id, $pos2+1);
				$res[] = array('mailid' => $i);

			}
		}

		return $res;
	}

	protected function _cmd_uid_store($argv) {
		$id = array_shift($argv); // 1:*
		$what = strtolower(array_shift($argv));

		$mode = 'set';
		$silent = false;

		if ($what[0] == '+') {
			$mode = 'add';
			$what = substr($what, 1);
		} else if ($what[0] == '-') {
			$mode = 'sub';
			$what = substr($what, 1);
		}

		if (substr($what, -7) == '.silent') {
			$what = substr($what, 0, -7);
			$silent = true;
		}

		if ($what != 'flags') {
			$this->sendMsg('BAD Setting '.strtoupper($what).' not supported');
			return;
		}

		$argv = implode(' ', $argv);
		$flags = $this->parseFetchParam($argv);

		foreach($this->transformRange($id) as $where) {
			$this->storeFlags($where, $mode, $flags);
			if (!$silent)
				$this->fetchMailByUid($where, array('FLAGS'));
		}

		$this->sendMsg('OK STORE completed');
	}

	protected function _cmd_uid_copy($argv) {
		// we will assume we never modify a file once received, and use link()
		$id = array_shift($argv);
		$box = $this->lookupFolder(array_shift($argv));
		if (is_null($box)) {
			$this->sendMsg('NO [TRYCREATE] No such mailbox');
			return;
		}
		if (isset($box['flags']['noselect'])) return $this->sendMsg('NO This folder has \\Noselect flag');
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');

		// invoke MailTarget
		$class = relativeclass($this, 'MTA\\MailTarget');
		$mailTarget = new $class('', '', $this->localConfig, $this->IPC);

		foreach($this->transformRange($id) as $where) {
			$result = $DAO_mails->loadByField(array('userid' => $this->info['account']->id, 'folder' => $this->selectedFolder)+$where);
			foreach($result as $mail) {
				// copy this mail, but first generate an unique id
				$new = $mailTarget->makeUniq('domains', $this->info['domainid'], $this->info['account']->id);
				link($this->mailPath($mail->uniqname), $new);
				$flags = array_flip(explode(',', $mail->flags));
				$flags['recent'] = 'recent';
				$flags = implode(',', array_flip($flags));
				// insert mail
				$DAO_mails->insertValues(array(
					'folder' => $box['id'],
					'userid' => $this->info['account']->id,
					'size' => $mail->size,
					'uniqname' => basename($new),
					'flags' => $flags,
				));
				$newid = $this->sql->insert_id;
				$this->IPC->broadcast('PMaild::Activity_'.$this->info['domainid'].'_'.$this->info['account']->id.'_'.$box['id'], array($newid, 'EXISTS'));
				// copy headers
			}
		}

		$this->sendMsg('OK COPY completed');
	}

	function _cmd_status($argv) {
		// We got STATUS folder (...)
		array_shift($argv); // "STATUS"
		$box_name = array_shift($argv);
		$box = $this->lookupFolder($box_name);
		$opt = $this->parseFetchParam(implode(' ', $argv));

		if (isset($box['flags']['noselect'])) return $this->sendMsg('NO This folder has \\Noselect flag');
		// TODO: find a way to do this without SQL code?
		$req = 'SELECT `flags`, COUNT(1) AS num, (MAX(`mailid`)+1) AS uidnext FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' AND `folder` = \''.$this->sql->escape_string($box['id']).'\' GROUP BY `flags`';
		$res = $this->sql->query($req);
		$total = 0;
		$recent = 0;
		$unseen = 0;
		$uidnext = 0;

		while($row = $res->fetch_assoc()) {
			$flags = array_flip(explode(',', $row['flags']));
			if (isset($flags['recent'])) $recent+=$row['num'];
			$total += $row['num'];
			if ($uidnext < $row['uidnext']) $uidnext = $row['uidnext'];
		}

		$res = array();
		foreach($opt as $o) {
			switch($o) {
				case 'MESSAGES': // How many messsages
					$res[] = 'MESSAGES';
					$res[] = $total;
					break;
				case 'RECENT': // How many recent msg
					$res[] = 'RECENT';
					$res[] = $recent;
					break;
				case 'UIDNEXT': // next UID
					$res[] = 'UIDNEXT';
					$res[] = $uidnext;
				case 'UIDVALIDITY': // uid validity
					$res[] = 'UIDVALIDITY';
					$res[] = $this->info['account']->id;
					break;
				case 'UNSEEN': // how many message do not have \seen
					$res[] = 'UNSEEN';
					$res[] = $unseen;
					break;
			}
		}

		$this->sendMsg('STATUS '.$box_name.' ('.implode(' ', $res).')', '*');
		$this->sendMsg('OK STATUS completed');
	}

	protected function idleFolderChanged() {
		$this->idle_queue = array();
		if (!is_null($this->idle_event))
			$this->IPC->unlistenBroadcast($this->idle_event, 'idle');

		if (is_null($this->selectedFolder)) {
			$this->idle_event = NULL;
			break;
		}

		$this->idle_event = 'PMaild::Activity_'.$this->info['domainid'].'_'.$this->info['account']->id.'_'.$this->selectedFolder;
		$this->IPC->listenBroadcast($this->idle_event, 'idle', array($this, 'receiveIdleEvent'));
	}

	public function receiveIdleEvent($data) {
		$evt = NULL;
		switch($data[1]) {
			case 'EXISTS':
				// new mail
				$id = $this->allocateQuickId($data[0]);
				$evt = $id.' EXISTS';
				break;
			case 'EXPUNGE':
				if (!isset($this->reverseMap[$data[0]])) break;
				$id = $this->reverseMap[$data[0]];
				$evt = $id.' EXPUNGE';
				unset($this->reverseMap[$data[0]]);
				break;
		}
		if (is_null($evt)) return;
		if ($this->idle_mode) {
			$this->sendMsg($evt, '*'); // in idle mode, send notification right now
			return;
		}
		$this->idle_queue[] = $evt; // queue for later, or for never
	}

	function _cmd_idle($argv) {
		if (is_null($this->selectedFolder)) {
			$this->sendMsg('NO no folder selected');
			return;
		}
		$this->idle_mode = true;
		$this->sendMsg('idling', '+');
		foreach($this->idle_queue as $line) $this->sendMsg($line, '*');
		$this->idle_queue = array();
	}

	function _cmd_append($argv) {
		// APPEND Folder Flagz Length
		$length = array_pop($argv); // {xxx}
		if (($length[0] != '{') || (substr($length, -1) != '}')) {
			$this->sendMsg('NO Bad length');
			return;
		}
		$length = (int)substr($length, 1, -1);
		if (!$length) {
			$this->sendMsg('NO Bad length');
			return;
		}

		array_shift($argv); // "APPEND"
		$box = $this->lookupFolder(array_shift($argv));
		if (is_null($box)) {
			$this->sendMsg('NO Mailbox not found');
			return;
		}
		// we might get flags, and date? let's say we only have flags
		$tmpflags = $this->parseFetchParam(implode(' ', $argv));
		$flags = array();
		foreach($tmpflags as $f) {
			$f = strtolower(substr($f, 1));
			$flags[$f] = $f;
		}
		// invoke MailTarget
		$class = relativeclass($this, 'MTA\\MailTarget');
		$mailTarget = new $class('', '', $this->localConfig);
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');

		// store this mail, but first generate an unique id
		$new = $mailTarget->makeUniq('domains', $this->info['domainid'], $this->info['account']->id);
		$fp = fopen($new, 'w+');

		$this->sendMsg('Ready for literal data', '+');

		$pos = 0;
		while($pos < $length) {
			$blen = $length - $pos;
			if ($blen > 8192) $blen = 8192;
			$buf = fread($this->fd, $blen);
			fwrite($fp, $buf);
			$pos += strlen($buf);

			// failed upload ?
			if (feof($this->fd)) {
				fclose($fp);
				@unlink($new);
				return;
			}
		}

		fgets($this->fd); // final ending empty line
		fclose($fp);
		$size = filesize($new);

		// insert mail
		$DAO_mails->insertValues($f = array(
			'folder' => $box['id'],
			'userid' => $this->info['account']->id,
			'size' => $size,
			'uniqname' => basename($new),
			'flags' => implode(',', $flags),
		));
		$newid = $this->sql->insert_id;
		$this->IPC->broadcast('PMaild::Activity_'.$this->info['domainid'].'_'.$this->info['account']->id.'_'.$box['id'], array($newid, 'EXISTS'));

		$this->sendMsg('OK APPEND completed');
	}
}

