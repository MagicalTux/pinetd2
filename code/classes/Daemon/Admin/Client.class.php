<?php

namespace Daemon\Admin;

use Daemon\HTTPd\HTTPAnswerError;

class Client extends \Daemon\HTTPd\Client {

	protected function handleRequest($path, $context) {
		echo 'foo';
//		var_dump($request, $headers, $cookies);
//		$answer = new HTTPAnswerError($this);
//		$answer->send(HTTPAnswerError::NOT_FOUND);
//		sleep(3);
	}

}

