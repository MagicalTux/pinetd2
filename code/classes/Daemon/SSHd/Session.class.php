<?php

namespace Daemon\SSHd;

class Session extends Channel {
	private $mode = NULL;

	protected function init($pkt) {
		// nothing to do, in fact :D
	}

	protected function parseBuffer() {
		if (is_null($this->mode)) return;
		$func = 'parseBuffer_'.$this->mode;
		$this->$func();

		if ($this->recv_spent > 1024) {
			// restore remote window
			$this->window($this->recv_spent);
			$this->recv_spent = 0;
		}
	}

	protected function parseBuffer_sftp() {
		var_dump(bin2hex($this->buf_in));
		$this->buf_in = '';
	}

	protected function parseBuffer_shell() {
		if (strpos($this->buf_in, "\x04") !== false) {
			$pos = strpos($this->buf_in, "\x04");
			if ($pos > 0) $this->send(substr($this->buf_in, 0, $pos));
			$this->send("\r\nGood bye!\r\n");
			$this->eof();
			$this->close();
			return;
		}
		$this->buf_in = str_replace("\r", "\r\n\$ ", $this->buf_in);
		$this->send($this->buf_in);
		$this->buf_in = '';
	}

	protected function _req_shell() {
		if (!is_null($this->mode)) return false;
		$this->mode = 'shell';
		$this->send("Welcome to the PHP/".phpversion()." SSH server!\r\n\r\n");
		$this->send("This may look like a shell but it's just echoing back to you whatever you type. Too bad heh!\r\n\r\n");
		$this->send("Press ^D to exit.\r\n\r\n");
		$this->send('$ ');
		return true; // I am a shell
	}

	protected function _req_subsystem($pkt) {
		$syst = $this->parseStr($pkt);
		if ($syst != 'sftp') return false;
		$class = $this->translate('SFTP');
		return $class->request('subsystem', $pkt);
	}
}

