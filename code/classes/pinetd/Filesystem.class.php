<?php

namespace pinetd;

class Filesystem {
	protected $cwd = '/';
	protected $root = NULL;
	protected $options;

	public function setOptions($options) {
		$this->options = $options;
	}

	public function getCwd() {
		$dir = $this->cwd;
		if (substr($dir, -1) != '/') $dir.='/';
		$root = $this->root;
		if (substr($root, -1) != '/') $root.='/';
		if (substr($dir, 0, strlen($root)) != $root) {
			$this->chDir('/');
			$cwd = '/';
		} else {
			$cwd = substr($dir, strlen($root)-1);
			if (strlen($cwd) > 1) $cwd = substr($cwd, 0, -1); // strip trailing /
		}
		return $cwd;
	}

	public function isWritable($file) {
		if (!$this->options['write_level']) return true;
		if ($file[0] != '/') $file = $this->cwd.'/'.$file;
		if ($file[0] != '/') $file = '/'.$file;
		while($file_p != $file) {
			$file_p = $file;
			$file = preg_replace('#/{2,}#', '/', $file);
			$file = preg_replace('#/\\.\\./[^/]+/#', '/', $file);
			$file = preg_replace('#/[^/]+/\\.\\./#', '/', $file);
		}
		$count = count(explode('/', $file))-2;
		if ($count < $this->options['write_level']) return false;
		return true;
	}

	public function chDir($dir) {
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
		$final_elem = array_pop($path);
		$path[] = NULL;

		foreach($path as $elem) {
			if (is_null($elem)) {
				$final = true;
				$elem = $final_elem;
			} else {
				$final = false;
			}

			if (($elem == '') || ($elem == '.')) continue; // no effect
			if ($elem == '..') {
				array_pop($final_path);
				continue;
			}
			$realpath = $this->root . '/' . implode('/', $final_path). '/' . $elem; // final path to $elem

			if ((!$final) && (!file_exists($realpath))) return false;

			if (is_link($realpath)) {
				if ($depth > 15) {
					// WTF?!!!
					return NULL;
				}
				$link = readlink($realpath);
				if ($link[0] == '/') {
					$new_path = $this->convertPath($link, $this->root . '/' . implode('/', $final_path), $depth + 1);
				} else {
					$new_path = $this->convertPath($link, $this->root . '/' . implode('/', $final_path) . '/' . $link, $depth + 1);
				}

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

	public function setRoot($root, $can_chroot = false) {
		if (!chdir($root)) return false;

		if ($can_chroot) {
			if (!chroot($root)) return false;
			$this->root = '/';
			return true;
		}

		$this->root = $root;
		return true;
	}

	public function realpath($path) {
		$fil = $this->convertPath($path);
		if ((is_null($fil)) || ($fil === false)) return NULL;
		return $fil;
	}

	public function openDir($dir) {
		$fil = $this->convertPath($dir);
		if ((is_null($fil)) || ($fil === false)) return NULL;

		$full_path = $this->root . $fil;

		clearstatcache();
		$dir = @opendir($full_path);

		if (!$dir) return NULL;

		return array('type' => 'dir', 'handle' => $dir, 'path' => $full_path);
	}

	public function readDir($dir) {
		if ($dir['type'] != 'dir') return false;
		$fil = readdir($dir['handle']);
		if ($fil === false) return NULL;
		return $this->_stat($dir['path'].'/'.$fil);
	}

	public function closeDir($dir) {
		if ($dir['type'] != 'dir') return false;
		closedir($dir['handle']);
		return true;
	}

	public function listDir($dir) {
		$fil = $this->convertPath($dir);
		if ((is_null($fil)) || ($fil === false)) return NULL;

		$full_path = $this->root . $fil;

		clearstatcache();
		$dir = @opendir($full_path);

		if (!$dir) return NULL;

		$res = array();

		while(($fil = readdir($dir)) !== false) {
			$res[] = $this->_stat($full_path.'/'.$fil);
		}

		closedir($dir);

		return $res;
	}

	public function stat($fil) {
		$fil = $this->convertPath($fil);
		if ((is_null($fil)) || ($fil === false)) return false;

		return $this->_stat($this->root . $fil);
	}

	protected function _basename($name, $ext = '') {
		$name = explode('/', $name);
		$name = array_pop($name);

		if ((strlen($ext) > 0) && (substr($name, 0 - strlen($ext)) == $ext))
			$name = substr($name, 0, 0 - strlen($ext));

		return $name;
	}

	protected function _stat($fil, $follow = true) {
		if ($follow) {
			$stat = @stat($fil);
		} else {
			$stat = @lstat($file);
		}

		if (!$stat) return false;
		
		$flag = '-rwx';
		if (is_dir($fil)) $flag='drwx';
		if (is_link($fil)) $flag='lrwx';
		$mode=substr(decbin($stat["mode"]),-3);
		if (substr($mode,0,1)=="1") $xflg ="r"; else $xflg ="-";
		if (substr($mode,1,1)=="1") $xflg.="w"; else $xflg.="-";
		if (substr($mode,2,1)=="1") $xflg.="x"; else $xflg.="-";
		$flag.=$xflg.$xflg;
		$blocks=$stat["nlink"];

		// FTP-like stat line
		list($year, $month, $day, $hour, $mins) = explode('|', date('Y|M|d|H|i', $stat['mtime']));

		// $timeline: same year: "HH:SS". Other: " YYYY" (%5d)
		if ($year == date('Y')) {
			$timeline = sprintf('%02d:%02d', $hour, $mins);
		} else {
			$timeline = sprintf(' %04d', $year);
		}

		$res = sprintf('%s %3u %-8d %-8d %8u %s %2d %s %s',
			$flag,
			$stat['nlink']?:1, /* TODO: nlinks */
			0, /* owner id */
			0, /* group id */
			$stat['size'], /* size */
			$month, /* month name */
			$day,
			$timeline,
			$this->_basename($fil)
		);

		if (is_link($fil)) {
			$res.=" -> ".readlink($fil);
		}

		$data = array(
			'name' => $this->_basename($fil),
			'flags' => $flag,
			'mode' => $stat['mode'],
			'blocks' => $blocks,
			'size' => $stat['size'],
			'atime' => $stat['atime'],
			'mtime' => $stat['mtime'],
			'text' => $res,
		);

		if (is_link($fil)) $data['link'] = readlink($fil);

		return $data;
	}

	public function open($file, $mode) {
		if ($mode[0] != 'r') {
			if (!$this->isWritable($file)) return false;
		}
		$fil = $this->convertPath($file);
		if ((is_null($fil)) || ($fil === false)) return false;

		if (($mode[0] != 'r') && (file_exists($fil))) {
			chmod($fil, 0755);
		}

		$fil = $this->root . $fil;
		return fopen($fil, $mode);
	}

	public function close($fp) {
		if (is_resource($fp)) {
			fclose($fp);
			return true;
		}
		switch($fp['type']) {
			case 'dir': closedir($fp['handle']); break;
			case 'file': fclose($fp['handle']); break;
			default: return false;
		}
		return true;
	}

	public function doRecursiveRMD($dir) {
		if (!$this->isWritable($dir)) return false;
		
		$fil = $this->convertPath($fullarg);
		if ((is_null($fil)) || ($fil === false)) return false;

		return $this->_doRecursiveRMD($this->root . $fil);
	}

	private function _doRecursiveRMD($dir) {
		$dh = opendir($dir);
		while(($fil = readdir($dh)) !== false) {
			if (($fil == '.') || ($fil == '..')) continue;
			$f = $dir.'/'.$fil;
			if (is_dir($f)) {
				$this->_doRecursiveRMD($f);
			} else {
				@unlink($f);
			}
		}
		closedir($dh);
		@rmdir($dir);

		return true;
	}

	public function rmDir($dir) {
		if (!$this->isWritable($dir)) return false;
		
		$fil = $this->convertPath($dir);
		if ((is_null($fil)) || ($fil === false)) return false;

		return @rmdir($this->root . $fil);
	}

	public function mkDir($dir, $mode = 0777) {
		if (!$this->isWritable($dir)) return false;
		
		$fil = $this->convertPath($dir);
		if ((is_null($fil)) || ($fil === false)) return false;

		return @mkdir($this->root . $fil, $mode);
	}

	public function size($fil) {
		$fil = $this->convertPath($fil);
		if ((is_null($fil)) || ($fil === false)) return false;

		return @filesize($this->root . $fil);
	}

	public function unLink($fil) {
		if (!$this->isWritable($fil)) return false;
		
		$fil = $this->convertPath($fil);
		if ((is_null($fil)) || ($fil === false)) return false;

		return @unlink($this->root . $fil);
	}

	public function rename($from, $to) {

		if (!$this->isWritable($from)) return false;
		if (!$this->isWritable($to)) return false;

		$from = $this->convertPath($from);
		if ((is_null($from)) || ($from === false)) return false;
		$to = $this->convertPath($to);
		if ((is_null($to)) || ($to === false)) return false;

		return @rename($this->root . $from, $this->root . $to);
	}

	public function fileExists($fil) {
		$fil = $this->convertPath($fil);
		if ((is_null($fil)) || ($fil === false)) return false;

		return file_exists($this->root . $fil);
	}
}

