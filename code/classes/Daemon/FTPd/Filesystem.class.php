<?php

namespace Daemon\FTPd;

class Filesystem {
	protected $cwd = '/';
	protected $root = NULL;

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

		return $this->_stat($fil);
	}

	protected function _basename($name, $ext = '') {
		$name = explode('/', $name);
		$name = array_pop($name);

		if ((strlen($ext) > 0) && (substr($name, 0 - strlen($ext)) == $ext))
			$name = substr($name, 0, 0 - strlen($ext));

		return $name;
	}

	protected function _stat($fil) {
		$stat = stat($fil);

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


		$data = array(
			'name' => $this->_basename($fil),
			'flags' => $flag,
			'blocks' => $blocks,
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
		);

		if (is_link($fil)) $data['link'] = readlink($fil);

		return $data;
	}

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

	public function close($fp) {
		return fclose($fp);
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

	public function mkDir($dir) {
		if (!$this->isWritable($dir)) return false;
		
		$fil = $this->convertPath($dir);
		if ((is_null($fil)) || ($fil === false)) return false;

		return @mkdir($this->root . $fil);
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

