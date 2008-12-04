<?php

namespace Daemon\HTTPd;

class HTTPAnswerError {
	private $client;

	static $errors = array(
		self::BAD_REQUEST => array('Bad Request'),
		self::NOT_FOUND => array('Not Found'),
	);

	const BAD_REQUEST = 400;
	const NOT_FOUND = 404;
	
	function __construct($obj) {
		if (!is_a($obj, 'Daemon\\HTTPd\\Client')) {
			throw new Exception('Invalid type of parameter to HTTPErrorClode::__construct()');
		}
		$this->client = $obj;
	}

	function send($errno) {
		if (!isset(self::$errors[$errno])) throw new Exception(get_class($this).' error: bad error code provided: '.$errno);
		$reply = 'HTTP/1.0 '.$errno.' '.self::$errors[$errno][0]."\r\n";
		$reply.= 'Server: '.$this->client->getVersionString()."\r\n";
		$reply.= 'Content-Type: text/html'."\r\n";
		$reply.= "\r\n";
		$reply.= '<html><head><title>'.self::$errors[$errno][0].'</title></head>';
		$reply.= '<body><h1>'.self::$errors[$errno][0].'</h1>';
		$reply.= '</body></html>'."\r\n";
		$this->client->sendMsg($reply);
	}
}


