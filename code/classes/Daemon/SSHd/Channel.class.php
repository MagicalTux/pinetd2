<?php

namespace Daemon\SSHd;

class Channel {
	private $tp; // transport
	protected $channel;
	protected $window_out;
	protected $window_in;
	protected $packet_max_out;
	protected $packet_max_in;
	protected $buf_out = '';
	protected $buf_in = '';
	protected $closed = false;

	final public function __construct($tp, $channel, $window, $packet_max, $pkt) {
		$this->tp = $tp;
		$this->channel = $channel;
		$this->window_out = $window;
		$this->window_in = $window;
		$this->packet_max_out = $packet_max;
		$this->packet_max_in = 32768;
		$this->init($pkt);
	}

	// return the amount of bytes we allow remote party to send us
	public function remoteWindow() {
		return $this->window_in;
	}

	// max packet remote can send us (can only be changed in init())
	public function maxPacket() {
		return $this->packet_max_in;
	}

	public function specificConfirmationData() {
		// overload me to add data in the SSH_MSG_CHANNEL_OPEN_CONFIRMATION packet
		return '';
	}

	public function request($request, $data) {
		$func = '_req_'.str_replace('-', '_', $request);
		if (is_callable(array($this, $func))) return $this->$func($data);
		return false;
	}

	protected function parseBuffer() {
		// default implementation: echo (overload me!)
		$this->send($this->buf_in);
		if (strpos($this->buf_in, "\x04") !== false) {
			$this->eof();
			$this->close();
		}
		$this->buf_in = '';
	}

	final public function closed() {
		$this->closed = true;
	}

	final public function eof() {
		if ($this->closed) return false;
		$this->tp->channelEof($this->channel);
	}

	final public function close() {
		if ($this->closed) return false;
		$this->tp->channelClose($this->channel);
	}

	final public function recv($str) {
		$this->window_in -= strlen($str);
		$this->buf_in = $str;
		$this->parseBuffer();
	}

	final public function send($str) {
		if ($this->closed) return false;
		$this->buf_out .= $str;
		$max_send = min($this->window_out, strlen($this->buf_out), $this->packet_max_out);
		if ($max_send == 0) return;
		$this->tp->channelWrite($this->channel, substr($this->buf_out, 0, $max_send));
		$this->window_out -= $max_send;
		$this->buf_out = substr($this->buf_out, $max_send);
	}
}

