<?php
/**
 * \file Daemon/FTPd/Client.class.php
 * \brief FTP daemon core file - client side
 */

namespace Daemon::FTPd;

/**
 * \brief Our FTPd client class
 */
class Client extends pinetd::TCP::Client {
	private $login = null; /*!< login info */
	private $binary = false; /*!< default = ASCII mode */
	private $mode = null;
	private $noop = 0; /*!< noop counter */
	private $resume = 0; /*!< resume RECV or STOR */
	private $root = null;
	private $tmp_login = null;
	private $cwd = '/';
	private $rnfr = null; /*!< RENAME FROM (RNFR) state */

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
		$this->sendMsg('220-Welcome to SimpleFTPd v2.0 by MagicalTux <mark@kinoko.fr>');
		list($cur, $max) = $this->IPC->getUserCount();
		$this->sendMsg('220-You are user '.$cur.' on a maximum of '.$max.' users');
		$this->sendMsg('220 You are '.$this->getHostName().', connected to '.$this->IPC->getName());
		return true;
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
		return true;
	}

	/**
	 * \brief Check if the provided login/pass pair is correct. This is forwarded to Daemon::FTPd::Base
	 */
	protected function checkAccess($login, $pass) {
		return $this->IPC->checkAccess($login, $pass, $this->peer);
	}

	/**
	 * \brief Get CWD relative to the FTP root
	 */
	protected function getCwd() {
		$dir = $this->cwd;
		if (substr($dir, -1) != '/') $dir.='/';
		$root = $this->root;
		if (substr($root, -1) != '/') $root.='/';
		if (substr($dir, 0, strlen($root)) != $root) {
			$this->doChdir('/');
			$cwd = '/';
		} else {
			$cwd = substr($dir, strlen($root)-1);
			if (strlen($cwd) > 1) $cwd = substr($cwd, 0, -1); // strip trailing /
		}
		return $cwd;
	}

	/**
	 * \brief Do a chdir to a new directory
	 */
	protected function doChdir($dir) {
		$new_dir = $this->convertPath($dir);
		if ((is_null($new_dir)) || ($new_dir === false)) return false;
		$new_dir = $this->root . $new_dir;
		if (!is_dir($new_dir)) return false;
		$this->cwd = $new_dir;
		return true;
	}

	/**
	 * \brief Convert a given path by resolving links, and make sure we stay within the FTP root
	 */
	protected function convertPath($path, $cwd = null, $depth = 0) {
		$final_path = array();
		if ($path[0] != '/') {
			// start with CWD value splitted
			if (is_null($cwd)) $cwd = $this->getCwd();
			$start = explode('/', $cwd);
			foreach($start as $elem) if ($elem != '') $final_path[] = $elem;
		}

		$path = explode('/', $path);

		foreach($path as $elem) {
			if (($elem == '') || ($elem == '.')) continue; // no effect
			if ($elem == '..') {
				array_pop($final_path);
				continue;
			}
			$realpath = $this->root . '/' . implode('/', $final_path). '/' . $elem; // final path to $elem

			if (!file_exists($realpath)) return false;

			if (is_link($realpath)) {
				if ($depth > 15) {
					// WTF?!!!
					return NULL;
				}
				$link = readlink($realpath);
				$new_path = $this->convertPath($link, $this->root . '/' . implode('/', $final_path), $depth + 1);
				if (is_null($new_path)) return NULL; // infinite symlink?
				$new_path = explode('/', $new_path);
				$final_path = array();
				foreach($new_path as $xelem) if ($xelem != '') $final_path[] = $xelem;
				continue;
			}
			$final_path[] = $elem;
		}
		return '/' . implode('/', $final_path);
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
					$this->log(::pinetd::Logger::LOG_WARN, 'Could not connect to peer tcp://'.$this->mode['ip'].':'.$this->mode['port'].' in PORT mode: ['.$errno.'] '.$errstr);
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
		$this->log(::pinetd::Logger::LOG_DEBUG, 'UNKNOWN COMMAND: '.implode(' ', $argv));
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
			if ((!$root) || (!is_dir($root))) {
				$this->sendMsg('500 Anonymous FTP access is disabled on this server');
				return;
			}
			$SUID = $this->IPC->canSUID();
			if ($SUID) $SUID = new ::pinetd::SUID($SUID['User'], $SUID['Group']);
			if ($this->IPC->canChroot()) {
				if (!chroot($root)) {
					$this->sendMsg('500 An error occured while trying to access anonymous root');
					$this->log(::pinetd::Logger::LOG_ERR, 'chroot() failed for anonymous login in  '.$root);
					return;
				} else {
					$this->root = '/';
				}
			} else {
				$this->root = $root;
			}
			if ($SUID) {
				if (!$SUID->setIt()) {
					$this->sendMsg('500 An error occured while trying to access anonymous root');
					$this->log(::pinetd::Logger::LOG_ERR, 'setuid()/setgid() failed for anonymous login');
					// we most likely already chroot()ed, can't return at this point
					$this->close();
					$this->IPC->killSelf($this->fd);
					return;
				}
			}
			$this->login = 'ftp'; // keyword for "anonymous"
			$this->IPC->setLoggedIn($this->fd, $this->login);
			$this->sendMsg('230 Anonymous user logged in, welcome!');
			$this->doChdir('/');
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
		if ((!is_dir($root)) || (!chdir($root))) {
			$this->sendMsg('500 An error occured, please contact system administrator and try again later');
			$this->log(::pinetd::Logger::LOG_ERR, 'Could not find/chdir in root '.$root.' while logging in user '.$login);
			return;
		}
		$SUID = $this->IPC->canSUID();
		if ($SUID) {
			$user = null;
			$group = null;
			if (isset($res['user'])) $user = $res['user'];
			if (isset($res['group'])) $user = $res['group'];

			if (is_null($user)) $user = $SUID['User'];
			if (is_null($group)) $user = $SUID['Group'];

			$SUID = new ::pinetd::SUID($user, $group);
		} elseif (isset($res['user'])) {
			$this->sendMsg('500 An error occured, please contact system administrator and try again later');
			$this->log(::pinetd::Logger::LOG_ERR, 'Could not SUID while SUID is required by underlying auth mechanism while logging in user '.$login);
			return;
		}
		if ($this->IPC->canChroot()) {
			if (!chroot($root)) {
				$this->sendMsg('500 An error occured, please contact system administrator and try again later');
				$this->log(::pinetd::Logger::LOG_ERR, 'chroot() failed for login '.$login.' in '.$root);
				return;
			} else {
				$this->root = '/';
			}
		} else {
			$this->root = $root;
		}
		if ($SUID) {
			if (!$SUID->setIt()) {
				$this->sendMsg('500 An error occured, please contact system administrator and try again later');
				$this->log(::pinetd::Logger::LOG_ERR, 'setuid()/setgid() failed for login '.$login);
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
		if (isset($res['chdir'])) {
			$this->doChdir($res['chdir']);
		} else {
			$this->doChdir('/');
		}
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
		$cwd = $this->getCwd();
		$this->sendMsg('257 "'.$cwd.'" is your Current Working Directory');
	}

	function _cmd_cdup($argv) {
		$this->_cmd_cwd(array('CWD', '..'));
	}

	function _cmd_cwd($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		// new path in $argv[1]
		if (!$this->doChdir($argv[1])) {
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
			$this->log(::pinetd::Logger::LOG_WARN, 'Could not create FTP PASV socket on tcp://'.$pasv_ip.':0 - ['.$errno.'] '.$errstr);
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

	function _cmd_nlst($argv) {
		$argv[0] = 'NLST';
		return $this->_cmd_list($argv);
	}

	function _cmd_list($argv, $cmd, $fullarg) {
		// TODO: Implement handling of options/path to list
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$fil = $this->convertPath($fullarg);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 LIST: Directory not found or too many symlink levels');
			return;
		}

		clearstatcache();
		$dir = @opendir($fil);

		if (!$dir) {
			$this->sendMsg('500 LIST: Could not open this directory');
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

		if (!$dir) {
			fclose($sock);
			$this->sendMsg('226 Transmission complete');
			return;
		}

		if ($argv[0] == 'NLST') {
			while(($fil = readdir($dir)) !== false) {
				fputs($sock, $fil."\r\n");
			}
		} else {
			while(($fil = readdir($dir)) !== false) {
				$stat = stat($this->cwd.'/'.$fil);
				$flag = '-rwx';
				if (is_dir($this->cwd.'/'.$fil)) $flag='drwx';
				if (is_link($this->cwd.'/'.$fil)) $flag='lrwx';
				$mode=substr(decbin($stat["mode"]),-3);
				if (substr($mode,0,1)=="1") $xflg ="r"; else $xflg ="-";
				if (substr($mode,1,1)=="1") $xflg.="w"; else $xflg.="-";
				if (substr($mode,2,1)=="1") $xflg.="x"; else $xflg.="-";
				$flag.=$xflg.$xflg;
				$blocks=$stat["nlink"];
				$res=$flag." ".$blocks." ";
				$res .= '0	'; // user
				$res .= '0	'; // group
				$siz = str_pad($stat["size"], 8, ' ', STR_PAD_LEFT);
				$res.=$siz." "; // file size
				$ftime = filemtime($this->cwd.'/'.$fil); // moment de modification
				$res.=date("M",$ftime); // month in 3 letters
				$day = date("j",$ftime);
				while(strlen($day)<3) $day=" ".$day;
				$res.=$day;
				$res.=" ".date("H:i",$ftime);
				$res.=" ".$fil;
				if (is_link($this->cwd.'/'.$fil)) {
					// read the link
					$dest = readlink($this->cwd.'/'.$fil);
					$res.=" -> ".$dest;
				}
				fputs($sock, $res."\r\n");
			}
		}
		closedir($dir);
		$this->sendMsg('226 Transmission complete');
		fclose($sock);
	}

	function _cmd_retr($argv, $cmd, $fullarg) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$fil = $this->convertPath($fullarg);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 RETR: File not found or too many symlink levels');
			return;
		}
		$fil = $this->root . $fil;

		if (!is_file($fil)) {
			$this->sendMsg('500 RETR: file is not a file');
			return;
		}

		$fp = @fopen($fil, 'rb');
		if (!$fp) {
			$this->sendMsg('500 Could not open file for reading');
			return;
		}

		$size = filesize($fil);
		$resume = $this->resume;
		$this->resume = 0;
		if ($resume > $size) {
			$this->sendMsg('500 can\'t resume from after EOF');
			fclose($fp);
			return;
		}

		$size -= $resume;

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
		$res = stream_copy_to_stream($fp, $sock, $size, $resume);
		
		if ($res != $size) {
			$this->sendMsg('500 Xfer connection closed!)');
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

		$fil = $fullarg;
		$dir = $this->convertPath(dirname($fil));
		$fil = basename($fil);
		if ($dir === false) {
			$this->sendMsg('500 Before uploading a file to a dir, make sure this dir exists');
			return;
		}

		if (!$this->canWriteFile($dir.'/'.$fil)) {
			$this->sendMsg('500 Permission Denied');
			return;
		}

		$dir = $this->root . $dir;

		if (!is_dir($dir)) {
			$this->sendMsg('500 Provided path for uploaded file is not a directory');
			return;
		}

		$fp = fopen($dir.'/'.$fil, 'a');
		if (!$appe) {
			$size = filesize($dir.'/'.$fil);
			if ($size < $this->restore) {
				$this->sendMsg('500 Can\'t REST over EOF');
				fclose($fp);
				return;
			}
			fseek($fp, $this->restore);
			ftruncate($fp, $this->restore); // blah!
		}

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

	function _cmd_rmd($argv) {
		$argv[0] = 'RMD';
		return $this->_cmd_dele($argv);
	}

	function _cmd_rrmd($argv) {
		$argv[0] = 'RRMD';
		return $this->_cmd_dele($argv);
	}

	function doRecursiveRMD($dir) {
		$dh = opendir($dir);
		while(($fil = readdir($dh)) !== false) {
			if (($fil == '.') || ($fil == '..')) continue;
			$f = $dir.'/'.$fil;
			if (is_dir($f)) {
				$this->doRecursiveRMD($f);
			} else {
				@unlink($f);
			}
		}
		closedir($dh);
		@rmdir($dir);
		$this->updateQuota();
	}

	function _cmd_dele($argv) {
		// DELETE A file (unlink)
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$fil = $this->convertPath($argv[1]);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 Entry not found');
			return;
		}

		if (!$this->canWriteFile($fil)) {
			$this->sendMsg('500 Permission Denied');
			return;
		}

		$fil = $this->root . $fil;

		switch($argv[0]) {
			case 'RRMD':
				$this->doRecursiveRMD($fil);
				break;
			case 'RMD':
				@rmdir($fil);
				break;
			default:
				@unlink($fil);
		}

		if (file_exists($fil)) {
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
		$dir = $this->convertPath(dirname($fil));
		$fil = basename($fil);
		if ($dir === false) {
			$this->sendMsg('500 Before uploading a file to a dir, make sure this dir exists');
			return;
		}

		if (!$this->canWriteFile($dir.'/'.$fil)) {
			$this->sendMsg('500 Permission Denied');
			return;
		}

		$dir = $this->root . $dir;
		$fil = $dir . '/' . $fil;

		if (file_exists($fil)) {
			$this->sendMsg('500 An entry with same name already exists');
			return;
		}

		@mkdir($fil);
		if (!file_exists($fil)) {
			$this->sendMsg('500 MKD failed');
			return;
		}
		$this->sendMsg('221 Directory created');
		$this->updateQuota();
	}

	function _cmd_size($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$fil = $this->convertPath($argv[1]);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 File not found or too many symlink levels');
			return;
		}
		$fil = $this->root . $fil;

		if (!is_file($fil)) {
			$this->sendMsg('500 file is not a file');
			return;
		}

		$size = filesize($fil);
		$this->sendMsg('213 '.$size);
	}

	function _cmd_rnfr($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		$fil = $this->convertPath($argv[1]);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 File not found or too many symlink levels');
			return;
		}

		$this->sendMsg('350 File found. Please provide new name...');

		$this->rnfr = $fil;
	}

	function _cmd_rnto($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}

		if (is_null($this->rnfr)) {
			$this->sendMsg('500 please start with RNFR');
			return;
		}

		$out_file = basename($argv[1]);
		$out = $this->convertPath(dirname($argv[1]));
		$out .= '/' . $out_file;

		if (!$this->canWriteFile($out)) {
			$this->sendMsg('500 Unable to open output for writing: access denied');
			return;
		}

		$out = $this->root . $out;

		if (!is_dir(dirname($out))) {
			$this->sendMsg('500 If you move a file to a directory, make sure you move it to a directory');
			return;
		}

		if (!$this->canWriteFile(dirname($this->rnfr))) {
			$this->sendMsg('500 Can\'t remove origin file');
			return;
		}

		$fil = $this->root . $this->rnfr;
		$this->rnfr = null;

		if (!rename($fil, $out)) {
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

	function _cmd_mdtm($argv) {
		if (is_null($this->login)) {
			$this->sendMsg('500 Please login first!');
			return;
		}
		$fil = $this->convertPath($argv[1]);
		if ((is_null($fil)) || ($fil === false)) {
			$this->sendMsg('500 File not found or too many symlink levels');
			return;
		}
		$fil = $this->root . $fil;

		clearstatcache();
		$mtime = filemtime($fil);
		$this->sendMsg('213 '.date('YmdHis', $mtime));
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


