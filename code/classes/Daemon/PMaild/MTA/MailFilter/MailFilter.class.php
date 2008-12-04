<?php

namespace Daemon\PMaild\MTA\MailFilter;

class MailFilter {
	protected $list;
	protected $domain;
	protected $localConfig;

	protected function domainFlags() {
		$flags = $this->domain->flags;
		return array_flip(explode(',', $flags));
	}

	function __construct($list, $domain, $localConfig) { // comma-separated list
		$this->list = explode(',', $list);
		$this->domain = $domain;
		$this->localConfig = $localConfig;
	}

	function process(&$txn) {
		foreach($this->list as $item) {
			$func = 'run_'.$item;
			$res = $this->$func($txn);
			if (!is_null($res)) return $res;
		}
		return null;
	}
}

