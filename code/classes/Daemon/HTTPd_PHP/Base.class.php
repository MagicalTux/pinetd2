<?php
namespace Daemon\HTTPd_PHP;

class Base extends \Daemon\HTTPd\Base {
	protected $files_base;

	public function __construct($id, $daemon, $IPC, $node) {
		parent::__construct($id, $daemon, $IPC, $node);
		$this->files_base = PINETD_CLASS_ROOT.'/Daemon/'.$this->daemon['Daemon'].'/files'; // we do not sanitarize "Daemon" as it's entered by the admin anyway
	}

	public function _ChildIPC_getFilesBase() {
		return $this->files_base;
	}

	public function getFilesBase() {
		return $this->files_base;
	}
}

