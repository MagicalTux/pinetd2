<?php

namespace Daemon\SSHd;

class Filesystem extends \pinetd\Filesystem {
	const SSH_FILEXFER_ATTR_SIZE = 0x00000001;
	const SSH_FILEXFER_ATTR_UIDGID = 0x00000002;
	const SSH_FILEXFER_ATTR_PERMISSIONS = 0x00000004;
	const SSH_FILEXFER_ATTR_ACMODTIME = 0x00000008;
	const SSH_FILEXFER_ATTR_EXTENDED = 0x80000000;

	protected function _stat($fil, $follow = true) {
		$stat = parent::_stat($fil);
		if (!$stat) return false;

		$sftp_stat = pack('NNNNNN',
			self::SSH_FILEXFER_ATTR_SIZE | self::SSH_FILEXFER_ATTR_PERMISSIONS | self::SSH_FILEXFER_ATTR_ACMODTIME,
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

