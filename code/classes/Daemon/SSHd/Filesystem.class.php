<?php

namespace Daemon\SSHd;

class Filesystem extends \pinetd\Filesystem {
	protected function _stat($fil, $follow = true) {
		$stat = parent::_stat($fil);
		if (!$stat) return false;

		$sftp_stat = pack('NNNNNN',
			SFTP::SSH_FILEXFER_ATTR_SIZE | SFTP::SSH_FILEXFER_ATTR_PERMISSIONS | SFTP::SSH_FILEXFER_ATTR_ACMODTIME,
			// size
			($stat['size'] >> 32) & 0xffffffff,
			$stat['size'] & 0xffffffff,
			// perms
			$stat['mode'],
			// times (atime, mtime)
			$stat['atime'],
			$stat['mtime']);
		$stat['sftp'] = $sftp_stat;
		return $stat;
	}
}

