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
	protected $recv_spent = 0;

	final public function __construct($tp, $channel, $window, $packet_max, $pkt) {
		if (is_null($tp)) return;
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
		echo "Request for $request denied.\n";
		return false;
	}

	protected function parseBuffer() {
		// default implementation: echo (overload me!)
		$this->send($this->buf_in);
		$this->buf_in = '';

		// refill window if needed
		if ($this->recv_spent > 1024) {
			$this->window($this->recv_spent);
			$this->recv_spent = 0;
		}
	}

	final public function translate($class) {
		$class = relativeclass($this, 'SFTP');
		$class = new $class(NULL,NULL,NULL,NULL,NULL);
		$class->tp = $this->tp;
		$class->channel = $this->channel;
		$class->window_out = $this->window_out;
		$class->window_in = $this->window_in;
		$class->packet_max_out = $this->packet_max_out;
		$class->packet_max_in = $this->packet_max_in;
		$class->buf_out = $this->buf_out;
		$class->buf_in = $this->buf_in;
		$class->closed = $this->closed;
		$class->recv_spent = $this->recv_spent;

		$this->tp->channelChangeObject($this->channel, $class);

		$class->init_post();

		return $class;
	}

	final public function window($bytes) {
		$this->tp->channelWindow($this->channel, $bytes);
		$this->window_in += $bytes;
	}

	final public function closed() {
		$this->closed = true;
	}

	public function gotEof() {
		// overload me to receive EOF event
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
		$this->recv_spent += strlen($str);
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

	public function windowAdjust($bytes) {
		$this->window_out += $bytes;
		$this->send(''); // force flush if possible
	}

	protected function str($str) {
		return pack('N', strlen($str)).$str;
	}

	protected function parseStr(&$pkt) {
		list(,$len) = unpack('N', substr($pkt, 0, 4));
		if ($len == 0) {
			$pkt = substr($pkt, 4);
			return '';
		}
		if ($len+4 > strlen($pkt)) return false;
		$res = substr($pkt, 4, $len);
		$pkt = substr($pkt, $len+4);
		return $res;
	}
}

