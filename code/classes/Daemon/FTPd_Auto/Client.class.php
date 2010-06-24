<?php
namespace Daemon\FTPd_Auto;

class Client extends \Daemon\FTPd\Client {
	protected function checkAccess($login, $pass) {
		$data = $this->serverCall('getLogin', array('login' => $login));
		if (is_null($data)) return false;

		if (crypt($pass, $data['Password']) != $data['Password']) return false;

		return array(
			'root' => $data['Path'],
			'suid_user' => 'nobody', // force suid on login
			'suid_group' => 'nobody', // force suid on login
			'write_level' => $data['Access'],
		);
	}

	public function _cmd_update($argv) {
		if (!is_null($this->login)) {
			$this->sendMsg('500 Call error');
			return;
		}
		$service = $argv[1];
		$data = $this->serverCall('getService', array('service' => $service));
		if (!$data) {
			$this->sendMsg('500 Call error');
			return;
		}

		$path = $data['path'];
		$domain = $data['domain'];
		if (!is_dir($path)) {
			$test = '/www/'.$domain[0].'/'.$domain[1].'/'.substr($domain, 2);
			if (is_dir($test)) {
				mkdir(dirname($path), 0755, true);
				rename($test, $path);
			} else {
				mkdir($path, 0755, true);
			}
		}

		// make domain symlinks
		foreach($data['domains'] as $alias) {
			list($alias, $extra) = explode('+', $alias);
			$link_target = $domain;
			if (!is_null($extra)) $link_target .= '/'.$extra;
			if (is_link($alias)) {
				if (readlink($alias) == $link_target) continue;
			} else if (is_dir($alias)) continue;

			if (file_exists($alias)) @unlink($alias);
			if (is_link($alias)) @unlink($alias);

			$parent = dirname($alias);
			if (!is_dir($parent)) mkdir($parent, 0755, true);

			symlink($link_target, $alias);
		}

		// make subdomain describe files
		foreach($data['subdomains'] as $sub => $bin) {
			file_put_contents($path.'_'.$sub.'.config', $bin);
			if (!is_dir($path.'/'.$sub)) {
				mkdir($path.'/'.$sub, 0755, true);
				chown($path.'/'.$sub, 'nobody');
				chgrp($path.'/'.$sub, 'nobody');
			}
		}
		// extra folders
		foreach(array('sessions','includes') as $sub) {
			if (!is_dir($path.'/'.$sub)) {
				mkdir($path.'/'.$sub, 0755, true);
				chown($path.'/'.$sub, 'nobody');
				chgrp($path.'/'.$sub, 'nobody');
			}
		}

		// vhost removal
		$dh = opendir($path);
		while(($fil = readdir($dh)) !== false) {
			if (($fil == '.') || ($fil == '..')) continue;
			if ($fil == 'sessions') continue;
			if ($fil == 'includes') continue;
			if ($fil == '.svn') continue;
			if (isset($data['subdomains'][$fil])) continue;

			$archive = '/www/archive/'.date('Y-m-d').'/'.$domain.'_'.$fil.'_'.time();
			mkdir(dirname($archive), 0755, true);
			rename($path.'/'.$fil, $archive);
			rename($path.'_'.$fil.'.config', $archive.'.config');
		}

		$this->sendMsg('200 OK');
	}

	protected function serverCall($method, array $params) {
		$params['server'] = $this->IPC->getName();
		$headers = array(
			'X-IPC: STATIC',
			'X-Path: Service/Hosting::'.$method,
		);
		$ch = curl_init('http://www.uid.st/');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		return unserialize(curl_exec($ch));
	}
}

