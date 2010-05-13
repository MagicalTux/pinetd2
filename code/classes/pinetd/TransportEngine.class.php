<?php

namespace pinetd;

class TransportEngine {
	private $parent;

	public function __construct($parent) {
		$this->parent = $parent;
		$this->parent->createPort('@TRANSPORT', $this);
	}
}

