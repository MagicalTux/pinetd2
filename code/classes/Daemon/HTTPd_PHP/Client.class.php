<?php

namespace Daemon\HTTPd_PHP;

class Client extends \Daemon\HTTPd\Client {
	protected $headers_sent = false;

	public function handleRequest($request, $headers, $cookies) {
		// We got a request, prepare a sandbox :)

		$path = parse_url($request['path']);

		// build vars for $_SERVER
		$s_vars = array(
			'REMOTE_ADDR' => $this->peer[0],
			'SERVER_SIGNATURE' => $this->getVersionString(),
			'SERVER_SOFTWARE' => 'PInetd',
			'DOCUMENT_ROOT' => $base,
			'REMOTE_PORT' => $this->peer[1],
			'GATEWAY_INTERFACE' => 'CGI/1.1',
			'SERVER_PROTOCOL' => 'HTTP/'.$request['version'],
			'REQUEST_METHOD' => $request['method'],
			'QUERY_STRING' => $path['query'],
			'REQUEST_URI' => $request['path'],
			'SCRIPT_NAME' => $path['path'],
			'PHP_SELF' => $path['path'],
		);
		foreach($headers as $head => $data) {
			$head = 'HTTP_'.preg_replace('/[^A-Z0-9_]/', '_', strtoupper($head));
			$s_vars[$head] = $data[0][1];
		}

		// GET
		$g_vars = array();
		parse_str((string)$path['query'], $g_vars);

		// what shall I do there? TODO!

		$this->close();

//		var_dump($request);
	}

	public function _outputHandler($str) {
		// check if headers sent
		if (!$this->headers_sent) {
			$headers = 'HTTP/1.0 200 Ok'."\r\n";
			$headers.= 'Server: '.$this->getVersionString()."\r\n";
			$headers.= 'Content-Type: text/html'."\r\n";
			$this->sendMsg($headers."\r\n");
			$this->headers_sent = true;
		}

		$this->sendMsg($str);
		return '';
	}
}

