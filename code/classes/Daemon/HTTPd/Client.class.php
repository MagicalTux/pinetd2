<?php

namespace Daemon\HTTPd;

class Client extends \pinetd\TCP\Client {
	protected $header = array();
	protected $waitlen = 0;

	// outgoing stuff
	protected $headers_sent = false;
	protected $out_headers = array();

	public function welcomeUser() {
		return true;
	}

	public function sendBanner() {
	}

	public function getVersionString() {
		return $this->IPC->getVersionString();
	}

	protected function initRequest($request, $headers, $cookies, $post = NULL) {
		$this->headers_sent = false;
		$this->out_headers = array();
		$this->header('Server: '.$this->getVersionString());
		$this->header('Content-Type: text/html');

		ob_start(array($this, '_outputHandler'));

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

		$context = array(
			'_SERVER' => $s_vars,
			'_GET' => $g_vars,
			'_COOKIE' => $cookies,
		);

		$this->handleRequest($path['path'], $context);

		ob_end_flush();
		$this->close();
	}

	public function header($head, $replace = true) {
		$pos = strpos($head, ':');
		if ($pos === false) return;
		$type = strtolower(substr($head, 0, $pos));

		if ($replace) {
			$this->out_headers[$type] = array($head);
		} else {
			$this->out_headers[$type][] = $head;
		}
	}

	public function _outputHandler($str) {
		// check if headers sent
		if (!$this->headers_sent) {
			$headers = 'HTTP/1.0 200 Ok'."\r\n";
			// build headers
			foreach($this->out_headers as $type => $list) {
				foreach($list as $head)
					$headers .= $head . "\r\n";
			}
			$this->sendMsg($headers."\r\n");
			$this->headers_sent = true;
		}

		$this->sendMsg($str);
		return '';
	}

	protected function handleRequest($path, $context) {
		//var_dump($request, $headers, $cookies);
		$answer = new HTTPAnswerError($this);
		$answer->send(HTTPAnswerError::NOT_FOUND);
	}

	protected function decodeRequest($data = null) {
		// parse request headers
		$cookies = array();
		$headers = array();
		foreach($this->header as $id => $head) {
			if ($id == 0) {
				$request = $head;
				continue;
			}
			$pos = strpos($head, ':');
			if ($pos === false) {
				$answer = new HTTPAnswerError($this);
				$answer->send(HTTPAnswerError::BAD_REQUEST);
				$this->close();
				return;
			}
			$var = substr($head, 0, $pos);
			$val = ltrim(substr($head, $pos+1));
			$key = strtolower($var);
			if ($key == 'cookie') {
				$pos = strpos($val, '=');
				if ($pos === false) {
					$answer = new HTTPAnswerError($this);
					$answer->send(HTTPAnswerError::BAD_REQUEST);
					$this->close();
					return;
				}
				$var = substr($val, 0, $pos);
				$val = substr($val, $pos+1);
				$cookies[$var] = $val;
				continue;
			}
			$headers[$key][] = array($var, $val);
		}
		if (preg_match('#^([A-Z]+) ([^ ]+) HTTP/(1\.[01])$#', $request, $match) == 0) {
			$answer = new HTTPAnswerError($this);
			$answer->send(HTTPAnswerError::BAD_REQUEST);
			$this->close();
			return;
		}
		$request = array(
			'method' => $match[1],
			'path' => $match[2],
			'version' => $match[3],
		);
		$this->initRequest($request, $headers, $cookies);
	}

	protected function parseBuffer() {
		while($this->ok) {
			if ($this->waitlen > 0) {
				if (strlen($this->buf) < $this->waitlen) return;
				$data = substr($this->buf, 0, $this->waitlen);
				$this->buf = substr($this->buf, $this->waitlen);
				$this->initRequest($request, $headers, $cookies, $data);
				continue;
			}
			$pos = strpos($this->buf, "\n");
			if ($pos === false) return; // no request yet
			$pos++;
			$lin = substr($this->buf, 0, $pos);
			$this->buf = substr($this->buf, $pos);
			$lin = rtrim($lin);
			if ($lin == '') {
				$this->decodeRequest();
				continue;
			}
			$this->header[] = $lin;
		}
	}
}


