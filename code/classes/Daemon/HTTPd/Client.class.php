<?php

namespace Daemon::HTTPd;

class Client extends ::pinetd::TCP::Client {
	protected $header = array();
	protected $waitlen = 0;

	public function welcomeUser() {
		return true;
	}

	protected function sendBanner() {
	}

	public function getVersionString() {
		return $this->IPC->getVersionString();
	}

	public function handleRequest($request, $headers, $cookies) { // should be public to implement function virtual()
		var_dump($request, $headers, $cookies);
		$answer = new HTTPAnswerError($this);
		$answer->send(HTTPAnswerError::NOT_FOUND);
//		sleep(3);
		$this->close();
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
			$headers[$key] = array($var, $val);
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
		$this->handleRequest($request, $headers, $cookies);
	}

	protected function parseBuffer() {
		while($this->ok) {
			if ($this->waitlen > 0) {
				if (strlen($this->buf) < $this->waitlen) return;
				$data = substr($this->buf, 0, $this->waitlen);
				$this->buf = substr($this->buf, $this->waitlen);
				$this->handleRequest($data);
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


