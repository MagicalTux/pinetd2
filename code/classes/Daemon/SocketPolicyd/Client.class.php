<?php

namespace Daemon\SocketPolicyd;
use \SimpleXmlElement;

class Client extends \pinetd\TCP\Client {
	function welcomeUser() {
		$this->setMsgEnd("\0");
		return true; // returning false will close client
	}

	function sendBanner() {
		return true;
	}

	function cmd_policy_file_request() {
		$this->sendMsg($this->IPC->getPolicyData());
		$this->close();
	}

	protected function parseLine($lin) {
		try {
			$xml = new SimpleXmlElement($lin);
		} catch(\Exception $e) {
			$this->close();
			return;
		}

		$op = 'cmd_'.str_replace('-', '_', $xml->getName());
		$func = array($this, $op);
		if (!is_callable($func)) {
			$this->close();
			return;
		}

		call_user_func($func, $xml);
	}

	protected function parseBuffer() {
		while($this->ok) {
			$pos = strpos($this->buf, "\0");
			if ($pos === false) break;
			$lin = substr($this->buf, 0, $pos);
			$this->buf = substr($this->buf, $pos+1);
			
			if ($this->debug_fd)
				fwrite($this->debug_fd, '< '.rtrim($lin)."\n");

			$this->parseLine($lin);
		}
		$this->setProcessStatus(); // back to idle
	}
}

