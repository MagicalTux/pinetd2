<?php

namespace DAO;
use \ArrayAccess;
use \Exception;

abstract class Base implements ArrayAccess {
	// DAO objects are singletons
	protected $table = null;
	protected $key = null;
	protected $SQL = null;

	public function __construct($table, $key) {
		if (is_null($this->SQL)) throw new Exception('Do not create a new DAO::Base directly, please choose a DB driver');
		$this->table = $table;
		$this->key = $key;
	}

	public function __clone() {
		throw new Exception('Cloning a DAO is not allowed');
	}

	abstract public function deleteBean($bean);
	abstract public function delete(array $where);
	abstract public function createUpdateQuery($data, $table, $qwhere);
	abstract public function insertValues($data);
	abstract public function createSelectQuery($qtype = 'SELECT', $qfields = '*', $qtable = null, $qwhere = null, $order_by = null, $limit = null);

	public function generateBean($data) {
		return new Bean($this, $data);
	}

	public function updateValues($bean) {
		$new_data = $bean->getUpdatedProperties();
		if (!$new_data) return true; // nothing to do
		$key = $this->key;
		$key_val = $bean->_PK;
		return $this->createUpdateQuery($new_data, $this->table, array(array($key, $key_val)));
	}

	public function loadByField($where_data, $order_by = null, $limit = null) {
		$result = $this->createSelectQuery('SELECT', '*', $this->table, $where_data, $order_by, $limit);
		if (!is_object($result)) return null;
		if ($result->num_rows < 1) return array();
		$Bean = array();
		while($row = $result->fetch_assoc())
			$Bean[] = $this->generateBean($row);
		$result->close();
		return $Bean;
	}

	public function loadLast() {
		$res = $this->loadByField(array($this->key => NULL));
		if (!$res) return null;
		return $res[0];
	}

	public function countByField($where_data, $order_by = null, $limit = null) {
		$result = $this->createSelectQuery('SELECT', 'COUNT(1)', $this->table, $where_data, $order_by, $limit);
		if (!is_object($result)) return null;
		$result = $result->fetch_row();
		return $result[0];
	}

	public function loadFromId($id) {
		list($result) = $this->loadByField(array($this->key => $id));
		return $result;
	}

	public function getKey() {
		return $this->key;
	}

	public function offsetExists($id) {
		$result = $this->createSelectQuery('SELECT', '1', $this->table, array($this->key => $id));
		if (!is_object($result)) return null;
		if ($result->num_rows < 1) return false;
		return true;
	}

	public function offsetGet($id) {
		return $this->loadFromId($id);
	}

	public function offsetSet($id, $val) {
		throw new Exception('Can\'t directly set value for a primary key!');
	}

	public function offsetUnset($id) {
		throw new Exception('Not implemented yet'); // TODO: implement deletion by primary key
	}
}


