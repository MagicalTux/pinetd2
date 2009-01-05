<?php

namespace Daemon\HTTPd_PHP;

class Client extends \Daemon\HTTPd\Client {
	public function handleRequest($request, $headers, $cookies) {
		// We got a request, fill some fake stuff to make it look like a legitimate one :)
		foreach($headers as $head => $data) {
			$head = 'HTTP_'.preg_replace('/[^A-Z0-9_]/', '_', strtoupper($head));
			$_SERVER[$head] = $data[0][1];
		}
		var_dump($_SERVER);
		parent::handleRequest($request, $headers, $cookies);
	}
}

