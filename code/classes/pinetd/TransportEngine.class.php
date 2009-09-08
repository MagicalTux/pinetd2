<?php

namespace pinetd;

class TransportEngine {
	public function __construct($parent) {
		$parent->createPort('@TRANSPORT', $this);
	}
}

