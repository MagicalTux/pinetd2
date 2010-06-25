<?php

// SFTP according to: http://www.openssh.com/txt/draft-ietf-secsh-filexfer-02.txt

namespace Daemon\SSHd;

class SFTP extends Channel {
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

	protected function init($pkt) {
		// nothing to do, in fact :D
	}

	protected function parseBuffer() {
		var_dump(bin2hex($this->buf_in));
		$this->buf_in = '';
	}

	protected function _req_subsystem($pkt) {
		$syst = $this->parseStr($pkt);
		if ($syst != 'sftp') return false;
		return true;
	}
}

