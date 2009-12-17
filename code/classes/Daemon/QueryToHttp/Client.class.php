<?php

namespace Daemon\QueryToHttp;

class Client extends \pinetd\TCP\Client {
	private $ch;

	public function welcomeUser() {
		return true;
	}

	public function sendBanner() {
		$this->setMsgEnd('');
	}

	function shutdown() {
	}

	public function doResolve() {
		return;
	}

	protected function parseLine($lin) {
		$lin = rtrim($lin);
		if (is_null($this->ch)) {
			$this->ch = curl_init($this->IPC->getUrl());
			curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->ch, CURLOPT_HEADER, true);
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('X-Remote-Ip' => $this->peer[0], 'X-Remote-Port' => $this->peer[1], 'X-Remote-Host' => $this->peer[2]) + $this->IPC->getHeaders());
		}
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query(array('input' => $lin)));

		$res = curl_exec($this->ch);

		$header_len = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$headers = substr($res, 0, $header_len);
		$res = substr($res, $header_len);
		$this->sendMsg($res);
		$this->close(); // TODO: check if a header contains X-Socket: close and only close if present
	}
}

