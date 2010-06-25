<?php

// SFTP according to: http://www.openssh.com/txt/draft-ietf-secsh-filexfer-02.txt

namespace Daemon\SSHd;

class SFTP extends Channel {
	private $fs;

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

	const SSH_FILEXFER_ATTR_SIZE = 0x00000001;
	const SSH_FILEXFER_ATTR_UIDGID = 0x00000002;
	const SSH_FILEXFER_ATTR_PERMISSIONS = 0x00000004;
	const SSH_FILEXFER_ATTR_ACMODTIME = 0x00000008;
	const SSH_FILEXFER_ATTR_EXTENDED = 0x80000000;

	protected function init_post() {
		$class = relativeclass($this, 'FileSystem');
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
			case self::SSH_FXP_REALPATH:
				list(,$rid) = unpack('N', $packet);
				$packet = substr($packet, 4);
				$path = $this->parseStr($packet);
				$res = $this->fs->realpath($path);
				$this->sendFxpName($rid, array(array('filename' => $res, 'longname' => $res)));
				break;
			default:
				echo "Unknown packet id [$id]: ".bin2hex($packet)."\n";
		}
	}

	protected function sendFxpName($rid, array $files) {
		$packet = pack('CNN', self::SSH_FXP_NAME, $rid, count($files));
		foreach($files as $info) {
			$packet .= $this->str($info['filename']);
			$packet .= $this->str($info['longname']);
			$packet .= $info['attrs'] ?: pack('NN', 0,0);
		}
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
}

