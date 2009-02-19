<?php

namespace Daemon\Admin;

use Daemon\HTTPd\HTTPAnswerError;
use \JSRuntime;

class Client extends \Daemon\HTTPd\Client {

	protected function handleRequest($path, &$context) {
		while($path[0] == '/')
			$path = substr($path, 1);

		$elem = explode('/', $path);

		if ($elem[0] == 'static') {
			$this->handleStatic($path, $context);
			return;
		}

		$root = __DIR__;
		$static = $root . '/static';

		$this->sessionStart($context);

		echo ++$context['_SESSION']['foo'];

		// test
//		var_dump($elem);
//		var_dump($request, $headers, $cookies);
	}

	protected function handleStatic($path, $context) {
		$base_path = realpath(__DIR__ . '/static').'/';
		$path = realpath(__DIR__ . '/' . $path);
		if (substr($path, 0, strlen($base_path)) != $base_path) {
			echo '403 Denied';
			return;
		}
		$ext_pos = strrpos($path, '.');
		if ($ext_pos !== false) {
			$ext = strtolower(substr($path, $ext_pos+1));
		} else {
			$ext = '';
		}
		switch($ext) {
			case 'jpg':
				$mime = 'image/jpeg';
				break;
			default:
				$mime = 'text/plain';
				break;
		}
		$this->header('Content-Type: '.$mime);
		readfile($path);
	}
}

