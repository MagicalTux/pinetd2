<?php

namespace Daemon\FTPd;

class Filesystem extends \pinetd\Filesystem {
	public function open($file, $write, $resume) {
		if ($write) {
			if (!$this->isWritable($file)) return false;
		}
		
		$fil = $this->convertPath($file);
		if ((is_null($fil)) || ($fil === false)) return false;

		$fil = $this->root . $fil;

		if ($write) @touch($fil);
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
}

