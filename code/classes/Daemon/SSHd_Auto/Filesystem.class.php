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

	public function open($file, $write, $resume) {
		if ($write) {
			if (!$this->isWritable($file)) return false;
		}
		
		$fil = $this->convertPath($file);
		if ((is_null($fil)) || ($fil === false)) return false;

		$fil = $this->root . $fil;

		if ($write) {
			// make sure we can write
			chmod(dirname($fil), 0755);
			chmod($fil, 0755);
			@touch($fil);
		}
		$fp = fopen($fil, ($write?'rb+':'rb'));

		if (!$fp) return false;

		fseek($fp, 0, SEEK_END);
		$size = ftell($fp);

		if ($resume > $size) {
			fclose($fp);
			return false;
		}

		if ($resume == -1) { // APPEND
			fseek($fp, 0, SEEK_END);
		} else {
			fseek($fp, $resume, SEEK_SET);
			if ($write) ftruncate($fp, $resume);
		}

		return array('fp' => $fp, 'size' => $size);
	}

	public function unLink($fil) {
		if (!$this->isWritable($fil)) return false;
		
		$fil = $this->convertPath($fil);
		if ((is_null($fil)) || ($fil === false)) return false;

		// make sure we can delete
		chmod(dirname($this->root . $fil), 0755);
		chmod($this->root . $fil, 0755);

		return @unlink($this->root . $fil);
	}
}

