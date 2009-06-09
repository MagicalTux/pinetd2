<?php

namespace DAO;

class SQLite3 extends \DAO\Base {
	public function __construct($SQL, $table, $key) {
		$this->SQL = $SQL;
		parent::__construct($table, $key);
	}

	public function buildWhere($key, $key_val) {
		if (!is_array($key)) {
			return $this->buildWhere(array($key), array($key_val));
		}
		$res = '';
		foreach($key as $idx => $k) {
			$res.=($res == ''?'':' AND ').'`'.$k.'` = \''.$this->SQL->escape_string($key_val[$idx]).'\'';
		}
		return $res;
	}

	public function deleteBean($bean) {
		$key = $this->key;
		$query = 'DELETE FROM '.$this->formatTable($this->table).' WHERE '.$this->buildWhere($key, $bean->__get($key));
		return $this->SQL->query($query);
	}

	public function delete(array $where) {
		$query = 'DELETE FROM '.$this->formatTable($this->table).' WHERE '.$this->buildQuickWhere($where);
		return $this->SQL->query($query);
	}

	public function formatTable($table) {
		if (is_array($table)) {
			return '`'.implode('`.`',$table).'`';
		}
		return '`'.$table.'`';
	}

	public function createUpdateQuery($data, $table, $qwhere) {
		$query = '';
		foreach($data as $var=>$val) {
			$query .= ($query == ''?'':', ').'`'.$var.'` = '.(is_null($val)?'NULL':'\''.$this->SQL->escape_string($val).'\'');
		}
		$query = 'UPDATE '.$this->formatTable($table).' SET '.$query.' ';
		if (!is_null($qwhere)) {
			if (is_array($qwhere)) {
				$query.='WHERE ';
				$first = true;
				foreach($qwhere as $var=>$val) {
					if (is_int($var)) {
						$query.=($first?'':' AND ').$this->buildWhere($val[0], $val[1]);
					} else {
						$query.=($first?'':' AND ').'`'.$var.'` = \''.$this->SQL->escape_string($val).'\'';
					}
					$first = false;
				}
			} else {
				$query.='WHERE '.$qwhere;
			}
		}
		return $this->SQL->query($query);
	}

	public function insertValues($data) {
		$query = '';
		foreach($data as $var=>$val) {
			$query .= ($query == ''?'':', ').'`'.$var.'` = '.(is_null($val)?'NULL':'\''.$this->SQL->escape_string($val).'\'');
		}
		$query = 'INSERT INTO '.$this->formatTable($this->table).' SET '.$query;
		return $this->SQL->query($query);
	}

	protected function buildQuickWhere(array $qwhere) {
		$first = true;
		$query = '';
		foreach($qwhere as $var=>$val) {
			if ((is_int($var)) && (is_object($val))) {
				if (!($val instanceof \pinetd\SQL\Expr))
					throw new Exception('Expression of wrong type');
				$query.=($first?'':' AND ').$val;
			} else if (is_int($var)) {
				$query.=($first?'':' AND ').$this->buildWhere($val[0], $val[1]);
			} else {
				$query.=($first?'':' AND ').'`'.$var.'` '.(is_null($val)?'IS NULL':'= \''.$this->SQL->escape_string($val).'\'');
			}
			$first = false;
		}
		return $query;
	}

	public function createSelectQuery($qtype = 'SELECT', $qfields = '*', $qtable = null, $qwhere = null, $order_by = null, $limit = null) {
		$query = $qtype.' ';
		if (is_array($qfields)) {
			$first = true;
			foreach($qfields as $var) {
				$query.=($first?'':', ').'`'.$var.'`';
				$first = false;
			}
			$query.=' ';
		} else {
			$query.=$qfields.' ';
		}
		if (!is_null($qtable)) $query.='FROM '.$this->formatTable($qtable).' ';
		if (!is_null($qwhere)) {
			if (is_array($qwhere)) {
				$query.='WHERE ' . $this->buildQuickWhere($qwhere);
			} else {
				$query.='WHERE '.$qwhere;
			}
		}
		if (!is_null($order_by)) {
			if (is_array($order_by)) {
				$order = '';
				foreach($order_by as $field => $_order) {
					if (is_int($field)) {
						$field = $order;
						$_order = 'ASC';
					}
					$order .= ($order==''?'':', ').'`'.$field.'` '.$_order;
				}
			} else {
				$order = $order_by;
			}
			$query.=' ORDER BY '.$order;
		}
		if (!is_null($limit)) {
			$query.=' LIMIT '.$limit[0];
			if (isset($limit[1])) $query.=', '.$limit[1];
		}
		$res = $this->SQL->query($query);
		if (!$res) throw new \Exception($this->SQL->error);
		return $res;
	}

}


