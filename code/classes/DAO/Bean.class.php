<?php

namespace DAO;

class Bean extends ArrayObject {
	private $properties = array();
	private $prop_change = array();
	private $parent;

	public function __construct(&$parent, $data) {
		$this->properties = $data;
		$this->prop_change = array();
		$this->parent = $parent;
	}

	public function __get($prop) {
		if ($prop == '_PK') $prop = $this->parent->getKey(); // todo: handle array pk
		if (is_array($prop)) {
			$res = array();
			foreach($prop as $p) $res[] = $this->__get($p);
			return $res;
		}
		if (array_key_exists($prop, $this->prop_change)) return $this->prop_change[$prop];
		if (array_key_exists($prop, $this->properties)) return $this->properties[$prop];
		return null;
	}

	public function offsetGet($prop) {
		return $this->__get($prop);
	}

	public function __set($prop, $val) {
		if (is_array($prop)) {
			foreach($prop as $idx=>$p) {
				$this->__set($p, $val[$idx]);
			}
			return;
		}
		if (!array_key_exists($prop, $this->properties)) return;
		if ($this->properties[$prop] == $val) {
			if (isset($this->prop_change[$prop])) unset($this->prop_change[$prop]);
			return;
		}
		$this->prop_change[$prop] = $val;
		return;
	}

	public function offsetSet($prop, $val) {
		return $this->__set($prop, $val);
	}

	public function needUpdate() {
		return (bool)$this->prop_change; // true if any change is needed
	}

	public function getUpdatedProperties() {
		return $this->prop_change;
	}

	public function commit() {
		if (!$this->needUpdate()) return true; // nothing to do
		if (!$this->parent->updateValues($this)) return false;
		$this->properties = array_merge($this->properties, $this->prop_change);
		$this->prop_change = array();
		return true;
	}

	public function delete() {
		return $this->parent->deleteBean($this);
	}

	public function getProperties() {
		return array_merge($this->properties, $this->prop_change);
	}
}


