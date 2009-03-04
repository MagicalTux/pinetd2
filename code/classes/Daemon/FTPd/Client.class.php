<?php
/**
 * \file Daemon/FTPd/Client.class.php
 * \brief FTP daemon core file - client side
 */

namespace Daemon\FTPd;
use pinetd\Logger;
use pinetd\SUID;

/**
 * \brief Our FTPd client class
 */
class Client extends \pinetd\TCP\Client {
	private $login = null; /*!< login info */
	private $binary = false; /*!< default = ASCII mode */
	private $mode = null;
	private $noop = 0; /*!< noop counter */
	private $resume = 0; /*!< resume RECV or STOR */
	private $tmp_login = null;
	private $rnfr = null; /*!< RENAME FROM (RNFR) state */
	protected $fs;

	function __construct($fd, $peer, $parent, $protocol) {
		parent::__construct($fd, $peer, $parent, $protocol);
		$this->setMsgEnd("\r\n");
	}

	/**
	 * \brief Tell the user to wait for resolve
	 */
	function welcomeUser() {
		$this->sendMsg('220-Looking up your hostname...');
		return true; // returning false will close client
	}

	/**
	 * \brief Send a nice header message
	 */
	function sendBanner() {
		$this->sendMsg('220-Welcome to SimpleFTPd v2.0 by MagicalTux <mark@gg.st>');
		list($cur, $max) = $this->IPC->getUserCount();
		$this->sendMsg('220-You are user '.$cur.' on a maximum of '.$max.' users');
		$this->sendMsg('220 You are '.$this->getHostName().', connected to '.$this->IPC->getName());

		// let's get a filesystem
		$class = relativeclass($this, 'Filesystem');
		$this->fs = new $class();

		return true;
	}

	protected function setProcessStatus($msg = '') {
		if ($msg == '') $msg = 'idle';
		if (is_null($this->login)) return parent::setProcessStatus('(not logged in) ' . $msg);
		return parent::setProcessStatus('('.$this->login.':'.$this->fs->getCwd().') '.$msg);
	}

	/**
	 * \brief Called on shutdown signal (daemon is stopping)
	 */
	function shutdown() {
		$this->sendMsg('500 Server is closing this link. Please reconnect later.');
	}

	/**
	 * \brief Returns true if a file is writable
	 */
	protected function canWriteFile($fil) {
		if (is_null($this->login)) return false;
		if ($this->login == 'ftp') return false;
		return $this->fs->isWritable($fil);
	}

	/**
	 * \brief Check if the provided login/pass pair is correct. This is forwarded to Daemon\FTPd\Base
	 */
	protected function checkAccess($login, $pass) {
		return $this->IPC->checkAccess($login, $pass, $this->peer);
	}

	/**
	 * Called when size used on FTP may have changed. If increment is provided, this function SHOULD NOT compute the size of the whole FTP, and instead increment used quota by $increment after having checked if it was OK
	 */
	protected function updateQuota($increment = null) {
		// noop
		return true;
	}

	/**
	 * \brief Clear current xfer mode, closing any open socket if needed
	 */
	protected function clearXferMode() {
		if (is_null($this->mode)) return;
		if ($this->mode['type'] == 'pasv') {
			fclose($this->mode['sock']);
		}
		$this->mode = null;
	}

	/**
	 * \brief initiate a xfer protocol (PORT or PASV) for future transmission
	 */
	protected function initiateXfer() {
		if (is_null($this->mode)) return false;
		switch($this->mode['type']) {
			case 'pasv':
				$sock = @stream_socket_accept($this->mode['sock'], 30);
				if (!$sock) return false;
				fclose($this->mode['sock']);
				$this->mode = null;
				return $sock;
			case 'port':
				$sock = @stream_socket_client('tcp://'.$this->mode['ip'].':'.$this->mode['port'], $errno, $errstr, 30);
				if (!$sock) {
					$this->log(Logger::LOG_WARN, 'Could not connect to peer tcp://'.$this->mode['ip'].':'.$this->mode['port'].' in PORT mode: ['.$errno.'] '.$errstr);
					return false;
				}
				$this->mode = null;
				return $sock;
		}
		return false;
	}

	/**
	 * \brief "Command not found" handler
	 */
	function _cmd_default($argv) {
		$this->sendMsg('500 Command '.$argv[0].' unknown!!');
		$this->log(Logger::LOG_DEBUG, 'UNKNOWN COMMAND: '.implode(' ', $argv));
	}

	function _cmd_quit() {
		$this->sendMsg('221 Good bye!');
		$this->close();
		$this->updateQuota();
	}

	function _cmd_allo() {
		return $this->_cmd_noop();
	}

	function _cmd_noop() {
		$this->sendMsg('200 Waitin\' for ya orders!');
	}

	function _cmd_user($argv) {
		if (!is_null($this->login)) {
			$this->sendMsg('500 Already logged in');
			return;
		}
		$login = $argv[1];
		if ((($login == 'ftp') || ($login == 'anonymous') || ($login == 'anon')) &&
				($root = $this->IPC->getAnonymousRoot())) {
			// check for max anonymous
			list($cur, $max) = $this->IPC->getAnonymousCount();
			if ($cur >= $max) {
				$this->sendMsg('500 Too many anonymous users logged in, please try again later!');
				return;
			}
			if (!$root) {
				$this->sendMsg('500 Anonymous FTP access is disabled on this server');
				return;
			}
			$SUID = $this->IPC->canSUID();
			if ($SUID) $SUID = new SUID($SUID['User'], $SUID['Group']);

			if (!$this->fs->setRoot($root, $this->IPC->canChroot())) {
				$this->sendMsg('500 An error occured while trying to access anonymous root');
				$this->log(Logger::LOG_ERR, 'chroot() failed for anonymous login in  '.$root);
				return;
			}

			if ($SUID) {
				if (!$SUID->setIt()) {
					$this->sendMsg('500 An error occured while trying to access anonymous root');
					$this->log(Logger::LOG_ERR, 'setuid()/setgid() failed for anonymous login');
					// we most likely already chroot()ed, can't return at this point
					$this->close();
					$this->IPC->killSelf($this->fd);
					return;
				}
			}
			$this->login = 'ftp'; // keyword for "anonymous"
			$this->IPC->setLoggedIn($this->fd, $this->login);
			$this->sendMsg('230 Anonymous user logged in, welcome!');
			return;
		}
		$this->tmp_login = $login;
		$this->sendMsg('331 Please provide password for user '.$login);
	}

	function _cmd_pass($argv) {
		if ($this->login == 'ftp') {
			$this->sendMsg('230 No password required for anonymous');
			return;
		}
		if (!is_null($this->login)) {
			$this->sendMsg('500 Already logged in!');
			return;
		}
		if (is_null($this->tmp_login)) {
			$this->sendMsg('500 Please send USER before sending PASS');
			return;
		}
		$login = $this->tmp_login;
		$pass = $argv[1];
		$res = $this->checkAccess($login, $pass);
		if ((!$res) || (!isset($res['root']))) {
			sleep(4); // TODO: This may cause a DoS if running without fork, detect case and act (if $this->IPC isa IPC)
			$this->sendMsg('500 Login or password may be invalid, please check again');
			return;
		}
		$root = $res['root'];

		$SUID = $this->IPC->canSUID();
		if ($SUID) {
			$user = null;
			$group = null;
			if (isset($res['user'])) $user = $res['user'];
			if (isset($res['group'])) $user = $res['group'];

			if (is_null($user)) $user = $SUID['User'];
			if (is_null($group)) $user = $SUID['Group'];

			$SUID = new SUID($user, $group);
		} elseif (isset($res['user'])) {
			$this->sendMsg('500 An error occured, please contact system administrator and try again later');
			$this->log(Logger::LOG_ERR, 'Could not SUID while SUID is required by underlying auth mechanism while logging in user '.$login);
			return;
		}

		if (!$this->fs->setRoot($root, $this->IPC->canChroot())) {
			$this->sendMsg('500 An error occured, please contact system administrator and try again later');
			$this->log(Logger::LOG_ERR, 'chroot() failed for login '.$login.' in '.$root);
			return;
		}

		if ($SUID) {
			if (!$SUID->setIt()) {
				$this->sendMsg('500 An error occured, please contact system administrator and try again later');
				$this->log(Logger::LOG_ERR, 'setuid()/setgid() failed for login '.$login);
				// we most likely already chroot()ed, can't turn back at this point
				$this->close();
				$this->IPC->killSelf($this->fd);
				return;
			}
		}

		$this->login = $login;
		$this->IPC->setLoggedIn($this->fd, $this->login);
		if (isset($res['banner'])) {
			foreach($res['banner'] as $lin) $this->sendMsg('230-'.$lin);
		}
		$this->sendMsg('230 Login success, welcome to your FTP');

		if (isset($res['chdir']))
			$this->fs->chDir($res['chdir']);

		$this->updateQuota();
	}

	function _cmd_syst() {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$this->sendMsg('215 UNIX Type: L8');
	}

	function _cmd_type($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		switch(strtoupper($argv[1])) {
			case 'A':
				$this->binary = false;
				$this->sendMsg('200 TYPE set to ASCII');
				return;
			case 'I':
				$this->binary = true;
				$this->sendMsg('200 TYPE set to BINARY');
				return;
			default:
				$this->sendMsg('501 You can only use TYPE A or TYPE I');
		}
	}

	function _cmd_pwd() {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$cwd = $this->fs->getCwd();
		$this->sendMsg('257 "'.$cwd.'" is your Current Working Directory');
	}

	function _cmd_cdup($argv) {
		$this->_cmd_cwd(array('CWD', '..'), 'CWD', '..');
	}

	function _cmd_cwd($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		// new path in $fullarg
		if (!$this->fs->chDir($fullarg)) {
			$this->sendMsg('500 Couldn\'t change location');
		} else {
			$this->sendMsg('250 Directory changed');
		}
	}

	function _cmd_rest($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$this->restore = (int)$argv[1];
		$this->sendMsg('350 Restarting at '.$this->restore);
	}

	function _cmd_port($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		// INPUT : a,b,c,d,p1,p2 (all & 0xff)
		// IP: a.b.c.d
		// PORT: p1 | (p2 << 8)
		$data = explode(',', $argv[1]);
		if (count($data) != 6) {
			$this->sendMsg('500 Invalid PORT command, should be a,b,c,d,e,f');
			return;
		}
		foreach($data as &$val) $val = ((int)$val) & 0xff;
		$ip = $data[0].'.'.$data[1].'.'.$data[2].'.'.$data[3]; // original ip
		$port = $data[5] | ($data[4] << 8);
		if ($port < 1024) { // SAFETY CHECK
			$this->sendMsg('500 Invalid PORT command (port < 1024)');
			return;
		}
		if ($ip != $this->peer[0]) {
			if ($this->login == 'ftp') {
				$this->sendMsg('500 FXP denied to anonymous users');
				return;
			}
			$this->sendMsg('200-FXP initialized to '.$ip);
		}
		$this->clearXferMode();
		$this->mode = array(
			'type' => 'port',
			'ip' => $ip,
			'port' => $port,
		);
		$this->sendMsg('200 PORT command successful');
	}

// TODO: http://www.faqs.org/rfcs/rfc2428.html
	function _cmd_eprt($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		// INPUT : |num|ip|port|
		// IP: a.b.c.d
		$data = explode('|', $argv[1]);
		if (count($data) != 5) {
			$this->sendMsg('500 Invalid EPRT command, should be |1|ip|port|');
			return;
		}

		$proto = $data[1];
		$ip = $data[2];
		$port = $data[3];

		if ($port < 1024) { // SAFETY CHECK
			$this->sendMsg('500 Invalid PORT command (port < 1024)');
			return;
		}
		if ($ip != $this->peer[0]) {
			if ($this->login == 'ftp') {
				$this->sendMsg('500 FXP denied to anonymous users');
				return;
			}
			$this->sendMsg('200-FXP initialized to '.$ip);
		}
		$this->clearXferMode();
		$this->mode = array(
			'type' => 'port',
			'ip' => $ip,
			'port' => $port,
			'proto' => $proto,
		);
		$this->sendMsg('200 EPRT command successful');
	}

	function _cmd_pasv() {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		// get pasv_ip
		$pasv_ip = $this->IPC->getPasvIP();
		list($real_ip) = explode(':', stream_socket_get_name($this->fd, false));
		if (is_null($pasv_ip)) $pasv_ip = $real_ip;
		$sock = @stream_socket_server('tcp://'.$real_ip.':0', $errno, $errstr); // thanks god, php made this so easy
		if (!$sock) {
			$this->sendMsg('500 Couldn\'t create passive socket');
			$this->log(Logger::LOG_WARN, 'Could not create FTP PASV socket on tcp://'.$pasv_ip.':0 - ['.$errno.'] '.$errstr);
			return;
		}
		list(, $port) = explode(':', stream_socket_get_name($sock, false)); // fetch auto-assigned port
		$data = str_replace('.', ',', $pasv_ip);
		$data .= ',' . (($port >> 8) & 0xff);
		$data .= ',' . ($port & 0xff);
		$this->sendMsg('227 Entering passive mode ('.$data.')');
		$this->clearXferMode();
		$this->mode = array(
			'type' => 'pasv',
			'sock' => $sock,
			'ip' => $pasv_ip,
			'port' => $port,
		);
	}

	function _cmd_nlst($argv, $cmd, $fullarg) {
		$argv[0] = 'NLST';
		return $this->_cmd_list($argv, $cmd, $fullarg);
	}

	function _cmd_list($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		// TODO: Implement handling of options to list
		if ($fullarg[0] == '-') {
			// parameters passed? (-l, -la, -m, etc...)
			$pos = strpos($fullarg, ' ');
			if ($pos === false) {
				$fullarg = '';
			} else {
				$fullarg = ltrim(substr($fullarg, $pos+1));
			}
		}

		$list = $this->fs->listDir($fullarg);
		if (is_null($list)) {
			$this->sendMsg('500 LIST: Directory not found or too many symlink levels');
			return;
		}

		$sock = $this->initiateXfer();
		if (!$sock) {
			$this->sendMsg('500 Unable to initiate connection, please provide PORT/PASV, and make sure your firewall is configured correctly');
			return;
		}

		list($ip, $port) = explode(':', stream_socket_get_name($sock, true));
		if (($this->login == 'ftp') && ($this->peer[0] != $ip)) {
			fclose($sock);
			$this->sendMsg('500 FXP unallowed for anonymous users!');
			return;
		}

		$this->sendMsg('150 Connection with '.$ip.':'.$port.' is established');

		if (!$list) {
			fclose($sock);
			$this->sendMsg('226 Transmission complete');
			return;
		}

		if ($argv[0] == 'NLST') {
			foreach($list as $fdata) {
				fputs($sock, $fdata['name']."\r\n");
			}
		} else {
			foreach($list as $fdata) {
				$fil = $fdata['name'];
				$flag = $fdata['flags'];
				$blocks = $fdata['blocks'];

				$res=$flag." ".$blocks." ";
				$res .= '0	'; // user
				$res .= '0	'; // group
				$siz = str_pad($fdata["size"], 8, ' ', STR_PAD_LEFT);
				$res.=$siz." "; // file size
				$ftime = $fdata['mtime']; // moment de modification
				$res.=date("M",$ftime); // month in 3 letters
				$day = date("j",$ftime);
				while(strlen($day)<3) $day=" ".$day;
				$res.=$day;
				$res.=" ".date("H:i",$ftime);
				$res.=" ".$fil;

				if (isset($fdata['link'])) {
					// read the link
					$res.=" -> ".$fdata['link'];
				}
				fputs($sock, $res."\r\n");
			}
		}
		$this->sendMsg('226 Transmission complete');
		fclose($sock);
	}

	function _cmd_retr($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$resume = $this->restore;
		$this->restore = 0;
		$info = $this->fs->open($fullarg, false, $resume);

		if (!$info) {
			$this->sendMsg('500 RETR: File not found or too many symlink levels');
			return;
		}

		$fp = $info['fp'];
		$size = $info['size'];

		$size -= $resume;

		$this->setProcessStatus(strtoupper($cmd[0]).' '.$fil);

		$sock = $this->initiateXfer();
		if (!$sock) {
			$this->sendMsg('500 Unable to initiate connection, please provide PORT/PASV, and make sure your firewall is configured correctly');
			fclose($fp);
			return;
		}

		list($ip, $port) = explode(':', stream_socket_get_name($sock, true));
		if (($this->login == 'ftp') && ($this->peer[0] != $ip)) {
			fclose($sock);
			fclose($fp);
			$this->sendMsg('500 FXP unallowed for anonymous users!');
			return;
		}

		$this->sendMsg('150 '.$size.' bytes to send');

		// transmit file
		$res = stream_copy_to_stream($fp, $sock);
		
		if ($res != $size) {
			$this->sendMsg('500 Xfer connection closed!');
		} else {
			$this->sendMsg('226 Send terminated');
		}

		fclose($sock);
		fclose($fp);
	}

	function _cmd_appe($argv, $cmd, $fullarg) {
		$argv[0] = 'APPE';
		return $this->_cmd_stor($argv, 'APPE', $fullarg);
	}

	function _cmd_stor($argv, $cmd, $fullarg) {
		$appe = ($argv[0] == 'APPE');

		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		if (!$appe) {
			$resume = $this->restore;
			$this->restore = 0;
		} else {
			$resume = -1;
		}
		$info = $this->fs->open($fullarg, true, $resume);
		if (!$info) {
			$this->sendMsg('500 Failed to open file for writing');
			return;
		}

		$fp = $info['fp'];

		$this->setProcessStatus(strtoupper($cmd[0]).' '.$fil);

		// initiate link
		$sock = $this->initiateXfer();
		if (!$sock) {
			$this->sendMsg('500 Unable to initiate connection, please provide PORT/PASV, and make sure your firewall is configured correctly');
			fclose($fp);
			return;
		}

		list($ip, $port) = explode(':', stream_socket_get_name($sock, true));
		if (($this->login == 'ftp') && ($this->peer[0] != $ip)) {
			fclose($sock);
			fclose($fp);
			$this->sendMsg('500 FXP unallowed for anonymous users!');
			return;
		}

		$this->sendMsg('150 Ready for data stream, from '.$ip.':'.$port);
		stream_set_blocking($sock, true);
		$bytes = 0;
		while(1) {
			$data = fread($sock, 65535);
			if ($data === '') break;
			if ($data === false) break;
			if (!$this->updateQuota(strlen($data))) {
				$this->sendMsg('500 Quota exceed!');
				break;
			}
			fwrite($fp, $data);
			$bytes += strlen($data);
//			$bytes += stream_copy_to_stream($sock, $fp);
		}
		$this->sendMsg('226 '.$bytes.' bytes written');
		fclose($sock);
		fclose($fp);
		$this->updateQuota();
	}

	function _cmd_site($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		switch(strtolower($argv[1])) {
			case 'chmod':
				$this->sendMsg('200 Chmod not supported');
				return;
				// TODO: implement SITE MD5 and SITE SHA1
			default:
				$this->sendMsg('501 unknown SITE command');
				return;
		}
	}

	function _cmd_rmd($argv, $cmd, $fullarg) {
		$argv[0] = 'RMD';
		return $this->_cmd_dele($argv, $cmd, $fullarg);
	}

	function _cmd_rrmd($argv, $cmd, $fullarg) {
		$argv[0] = 'RRMD';
		return $this->_cmd_dele($argv, $cmd, $fullarg);
	}

	function _cmd_dele($argv, $cmd, $fullarg) {
		// DELETE A file (unlink)
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		switch($argv[0]) {
			case 'RRMD':
				$this->fs->doRecursiveRMD($fil);
				break;
			case 'RMD':
				$this->fs->rmDir($fil);
				break;
			default:
				$this->fs->unLink($fil);
		}

		if ($this->fs->fileExists($fil)) {
			$this->sendMsg('500 Operation failed');
			return;
		}
		$this->sendMsg('226 Entry removed');
		$this->updateQuota();
	}

	function _cmd_mkd($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$fil = $argv[1];

		if (!$this->fs->mkDir($fil)) {
			$this->sendMsg('500 MKD failed');
			return;
		}

		$this->sendMsg('221 Directory created');
		$this->updateQuota();
	}

	function _cmd_size($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$size = $this->fs->size($fullarg);
		if ($size === false) {
			$this->sendMsg('500 File not found or too many symlink levels');
			return;
		}

		$this->sendMsg('213 '.$size);
	}

	function _cmd_rnfr($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$this->sendMsg('350 Please provide new name...');

		$this->rnfr = $fullarg;
	}

	function _cmd_rnto($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		if (is_null($this->rnfr)) {
			$this->sendMsg('500 please start with RNFR');
			return;
		}

		if (!$this->fs->rename($this->rnfr, $fullarg)) {
			$this->sendMsg('500 Rename failed');
			return;
		}

		$this->sendMsg('221 Rename successful');
		$this->updateQuota();
	}

	function _cmd_mode($argv) {
		if ($argv[1] != 'S') {
			$this->sendMsg('504 Onmy mode S(stream) is supported.');
			return;
		}
		$this->sendMsg('200 S OK');
	}

	function _cmd_stru($argv) {
		if ($argv[1] != 'F') {
			$this->sendMsg('504 Only STRU F(file) is supported.');
			return;
		}
		$this->sendMsg('200 F OK');
	}

	function _cmd_mdtm($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$stat = $this->fs->stat($fullarg);

		if (!$stat) {
			$this->sendMsg('500 File not found or too many symlink levels');
			return;
		}

		$this->sendMsg('213 '.date('YmdHis', $stat['mtime']));
	}

	function _cmd_feat() {
		$this->sendMsg('211-Extensions supported:');
		$this->sendMsg('211-MDTM');
		$this->sendMsg('211-SIZE');
		$this->sendMsg('211-REST STREAM');
		$this->sendMsg('211-PASV');
		$this->sendMsg('211 End.');
	}
}


