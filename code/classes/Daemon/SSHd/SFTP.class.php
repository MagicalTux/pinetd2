<?php

// SFTP according to: http://www.openssh.com/txt/draft-ietf-secsh-filexfer-02.txt

namespace Daemon\SSHd;

class SFTP extends Channel {
	private $fs;
	private $handles;
	private $last_h = 0;

	const SSH_FXP_INIT = 1;
	const SSH_FXP_VERSION = 2;
	const SSH_FXP_OPEN = 3;
	const SSH_FXP_CLOSE = 4;
	const SSH_FXP_READ = 5;
	const SSH_FXP_WRITE = 6;
	const SSH_FXP_LSTAT = 7;
	const SSH_FXP_FSTAT = 8;
	const SSH_FXP_SETSTAT = 9;
	const SSH_FXP_FSETSTAT = 10;
	const SSH_FXP_OPENDIR = 11;
	const SSH_FXP_READDIR = 12;
	const SSH_FXP_REMOVE = 13;
	const SSH_FXP_MKDIR = 14;
	const SSH_FXP_RMDIR = 15;
	const SSH_FXP_REALPATH = 16;
	const SSH_FXP_STAT = 17;
	const SSH_FXP_RENAME = 18;
	const SSH_FXP_READLINK = 19;
	const SSH_FXP_SYMLINK = 20;
	const SSH_FXP_STATUS = 101;
	const SSH_FXP_HANDLE = 102;
	const SSH_FXP_DATA = 103;
	const SSH_FXP_NAME = 104;
	const SSH_FXP_ATTRS = 105;
	const SSH_FXP_EXTENDED = 200;
	const SSH_FXP_EXTENDED_REPLY = 201;

	const SSH_FX_OK = 0;
	const SSH_FX_EOF = 1;
	const SSH_FX_NO_SUCH_FILE = 2;
	const SSH_FX_PERMISSION_DENIED = 3;
	const SSH_FX_FAILURE = 4;
	const SSH_FX_BAD_MESSAGE = 5;
	const SSH_FX_NO_CONNECTION = 6;
	const SSH_FX_CONNECTION_LOST = 7;
	const SSH_FX_OP_UNSUPPORTED = 8;

	const SSH_FILEXFER_ATTR_SIZE = 0x00000001;
	const SSH_FILEXFER_ATTR_UIDGID = 0x00000002;
	const SSH_FILEXFER_ATTR_PERMISSIONS = 0x00000004;
	const SSH_FILEXFER_ATTR_ACMODTIME = 0x00000008;
	const SSH_FILEXFER_ATTR_EXTENDED = 0x80000000;

	protected function init_post() {
		$class = relativeclass($this, 'Filesystem');
		$this->fs = new $class();
		$this->fs->setRoot('/tmp');
	}

	protected function handlePacket($packet) {
		$id = ord($packet[0]);
		$packet = substr($packet, 1);
		switch($id) {
			case self::SSH_FXP_INIT:
				list(,$version) = unpack('N', $packet);
				if ($version < 3) return $this->close(); // we are too recent for this little guy
				$pkt = pack('CN', self::SSH_FXP_VERSION, 3);
				$this->sendPacket($pkt);
				break;
			case self::SSH_FXP_VERSION: break; // ignore it
			case self::SSH_FXP_CLOSE:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$h_bin = $this->parseStr($packet);
				$h = $this->getHandle($h_bin);
				if (!$h) {
					$this->sendStatus($rid, self::SSH_FX_FAILURE, 'Bad handle');
					break;
				}
				$this->fs->close($h);
				unset($this->handles[$h_bin]);
				$this->sendStatus($rid, self::SSH_FX_OK, 'OK');
				break;
			case self::SSH_FXP_OPENDIR:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$path = $this->parseStr($packet);
				$dir = $this->fs->opendir($path);
				if (!$dir) {
					$this->sendStatus($rid, self::SSH_FX_NO_SUCH_FILE, 'Unable to open dir');
					break;
				}
				$this->sendHandle($rid, $dir);
				break;
			case self::SSH_FXP_READDIR:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$h = $this->getHandle($this->parseStr($packet));
				if (!$h) {
					$this->sendStatus($rid, self::SSH_FX_FAILURE, 'Bad dir handle');
					break;
				}
				$list = array();
				for($i = 0; $i < 10; $i++) {
					$tmp = $this->fs->readDir($h);
					if ($tmp === false) {
						$list = false;
						break;
					}
					if (!$tmp) break;
					$list[] = array('filename' => $tmp['name'], 'longname' => $tmp['text'], 'attrs' => $tmp['sftp']);
				}
				if ($list === false) {
					$this->sendStatus($rid, self::SSH_FX_FAILURE, 'Bad dir handle');
					break;
				}
				if (!$list) {
					$this->sendStatus($rid, self::SSH_FX_EOF, 'EOF');
					break;
				}
				$this->sendFxpName($rid, $list);
				break;
			case self::SSH_FXP_STAT:
			case self::SSH_FXP_LSTAT:
				// 00000002000000012f
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$path = $this->parseStr($packet);
				$stat = $this->fs->stat($path, $id == self::SSH_FXP_STAT);
				if (!$stat) {
					$this->sendStatus($rid, self::SSH_FX_NO_SUCH_FILE, 'Unable to stat file');
				} else {
					$this->sendFxpAttrs($rid, $stat['sftp']);
				}
				break;
			case self::SSH_FXP_MKDIR:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$path = $this->parseStr($packet);
				$attrs = $this->parseAttrs($packet);
				if (!$this->fs->mkDir($path, $attrs['mode'] ?: 0777)) {
					$this->sendStatus($rid, self::SSH_FX_PERMISSION_DENIED, 'Unable to create dir');
					break;
				}
				$this->sendStatus($rid, self::SSH_FX_OK, 'OK');
				break;
			case self::SSH_FXP_REALPATH:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$path = $this->parseStr($packet);
				$res = $this->fs->realpath($path);
				if (!$res) {
					$this->sendStatus($rid, self::SSH_FX_NO_SUCH_FILE, 'Unable to realpath file');
				} else {
					$this->sendFxpName($rid, array(array('filename' => $res, 'longname' => $res)));
				}
				break;
			default:
				echo "Unknown packet id [$id]: ".bin2hex($packet)."\n";
				list(,$rid) = unpack('N', $packet);
				$this->sendStatus($rid, self::SSH_FX_OP_UNSUPPORTED, "Unknown packet id [$id]: ".bin2hex($packet));
		}
	}

	protected function sendFxpAttrs($rid, $attrs) {
		$packet = pack('CN', self::SSH_FXP_ATTRS, $rid).$attrs;
		$this->sendPacket($packet);
	}

	protected function sendFxpName($rid, array $files) {
		$packet = pack('CNN', self::SSH_FXP_NAME, $rid, count($files));
		foreach($files as $info) {
			$packet .= $this->str($info['filename']);
			$packet .= $this->str($info['longname']);
			$packet .= $info['attrs'] ?: pack('NN', 0,0);
		}
		$this->sendPacket($packet);
	}

	protected function parseBuffer() {
		if (strlen($this->buf_in) < 4) return; // not enough data yet
		list(,$len) = unpack('N', $this->buf_in);
		if (strlen($this->buf_in) < ($len+4)) return; // not enough data yet
		$packet = $this->parseStr($this->buf_in);
		$this->handlePacket($packet);
	}

	protected function _req_subsystem($pkt) {
		$syst = $this->parseStr($pkt);
		if ($syst != 'sftp') return false;
		return true;
	}

	protected function sendPacket($packet) {
		$this->send($this->str($packet));
	}

	protected function parseAttrs(&$pkt) {
		$flags = $this->parseInt32($pkt);
		$res = array();
		if ($flags & self::SSH_FILEXFER_ATTR_SIZE) {
			$res['size'] = $this->parseInt32($pkt) << 32;
			$res['size']|= $this->parseInt32($pkt);
		}
		if ($flags & self::SSH_FILEXFER_ATTR_UIDGID) {
			$res['uid'] = $this->parseInt32($pkt);
			$res['gid'] = $this->parseInt32($pkt);
		}
		if ($flags & self::SSH_FILEXFER_ATTR_PERMISSIONS) {
			$res['mode'] = $this->parseInt32($pkt);
		}
		if ($flags & self::SSH_FILEXFER_ATTR_ACMODTIME) {
			$res['atime'] = $this->parseInt32($pkt);
			$res['mtime'] = $this->parseInt32($pkt);
		}
		if ($flags & self::SSH_FILEXFER_ATTR_EXTENDED) {
			$res['ext'] = array();
			$count = $this->parseInt32($pkt);
			for($i = 0; $i < $count; $i++) {
				$key = $this->parseStr($pkt);
				$res['ext'][$key] = $this->parseStr($pkt);
			}
		}
		return $res;
	}

	protected function sendStatus($rid, $status, $msg) {
		$pkt = pack('CNN', self::SSH_FXP_STATUS, $rid, $status) . $this->str($msg) . $this->str('');
		$this->sendPacket($pkt);
	}

	protected function sendHandle($rid, $h) {
		if (!is_string($h)) $h = $this->makeHandle($h);
		$pkt = pack('CN', self::SSH_FXP_HANDLE, $rid).$this->str($h);
		$this->sendPacket($pkt);
	}

	public function gotEof() {
		// that's an exit request
		$this->eof();
		$this->close();
	}

	protected function makeHandle($res) {
		while(1) {
			$this->last_h = ($this->last_h+1) & 0xffffffff;
			$h = pack('N', $this->last_h);
			if (!isset($this->handles[$h])) {
				$this->handles[$h] = $res;
				return $h;
			}
		}
	}

	protected function getHandle($h) {
		if (!isset($this->handles[$h])) return false;
		return $this->handles[$h];
	}
}

