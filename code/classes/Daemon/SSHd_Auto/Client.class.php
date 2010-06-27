<?php
namespace Daemon\SSHd_Auto;

class Client extends \Daemon\SSHd\Client {
	protected function login($login, $pass, $service) {
		$data = $this->serverCall('getLogin', array('login' => $login));
		if (is_null($data)) return false;

		if (crypt($pass, $data['Password']) != $data['Password']) return false;

		$info = array(
			'root' => $data['Path'],
			'suid_user' => 'nobody', // force suid on login
			'suid_group' => 'nobody', // force suid on login
			'write_level' => $data['Access'],
		);
		return $this->doLogin($info);
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

