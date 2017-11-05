<?php

/**
 * Query2Builder is a class for building queries programatically
 *
 * @author Adam Zivner <adam.zivner@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD license
 * @package Query2
 * @link http://query2.0o.cz
 */
class Query2Builder
{
	protected $select = array(), $from = array(), $where = array(), $group_by = array(), $having = array(), $order_by = array();
	protected $limit;

	/** @return array of SQL chunks and arguments */
	public function mergeQuery()
	{
		$args = array();

		if (count($this->select)) {
			$args = array_merge($args, array("SELECT"), array_slice($this->select, 1));
		}
		if (count($this->from)) {
			$args = array_merge($args, array("FROM"), $this->from);
		}
		if (count($this->where)) {
			$args = array_merge($args, array("WHERE", "("), array_slice($this->where, 1), array(")"));
		}
		if (count($this->group_by)) {
			$args = array_merge($args, array("GROUP BY"), array_slice($this->group_by, 1));
		}
		if (count($this->having)) {
			$args = array_merge($args, array("HAVING", "("), array_slice($this->having, 1), array(")"));
		}
		if (count($this->order_by)) {
			$args = array_merge($args, array("ORDER BY"), array_slice($this->order_by, 1));
		}
		if (strlen($this->limit)) {
			$args = array_merge($args, array("LIMIT", $this->limit));
		}

		return $args;
	}

	public function select()
	{
		$args = func_get_args();
		$this->select = array_merge($this->select, array(","), $args);
		return $this;
	}

	public function from()
	{
		$args = func_get_args();
		$this->from = array_merge($this->from, $args);
		return $this;
	}

	public function whereAnd()
	{
		$this->where[] = " AND ";
		$this->where = $this->parseArgs(func_get_args(), $this->where);

		return $this;
	}

	public function whereOr()
	{
		$this->where[] = " OR ";
		$this->where = $this->parseArgs(func_get_args(), $this->where);

		return $this;
	}

	public function havingAnd()
	{
		$this->having[] = " AND ";
		$this->having = $this->parseArgs(func_get_args(), $this->having);

		return $this;
	}

	public function havingOr()
	{
		$this->having[] = " OR ";
		$this->having = $this->parseArgs(func_get_args(), $this->having);

		return $this;
	}

	public function groupBy()
	{
		$args = func_get_args();
		$this->group_by = array_merge($this->group_by, array(","), $args);
		return $this;
	}

	public function orderBy()
	{
		$args = func_get_args();
		$this->order_by = array_merge($this->order_by, array(","), $args);
		return $this;
	}

	/**
	 * @param string /integer $skip either first part of the limit
	 * @param integer $num if $skip is not string then this is the second part of limit statement
	 * @return $this
	 */
	public function limit($skip, $num = -1)
	{
		$this->limit = (int)$skip . ($num != -1 ? ", " . (int)$num : "");

		return $this;
	}

	// Clear methods allow to clear just some part of the query builder

	public function clearSelect()
	{
		$this->select = array();
		return $this;
	}

	public function clearFrom()
	{
		$this->from = array();
		return $this;
	}

	public function clearWhere()
	{
		$this->where = array();
		return $this;
	}

	public function clearOrderBy()
	{
		$this->order_by = array();
		return $this;
	}

	public function clearGroupBy()
	{
		$this->group_by = array();
		return $this;
	}

	public function clearHaving()
	{
		$this->having = array();
		return $this;
	}

	public function clearLimit()
	{
		$this->limit = "";
		return $this;
	}

	/**
	 * Adds $args into $arr. Instances of Query2Builder are merged into arrays.
	 * Used by whereOr(), whereAnd(), havingOr() and havingAnd()
	 *
	 * @param array $args - array of strings and Query2Builder objects
	 * @param array $arr
	 * @return array - array of strings
	 */

	protected function parseArgs($args, $arr)
	{
		foreach ($args as $arg) {
			if (is_object($arg) && method_exists($arg, 'mergeQuery')) // is this instance of Query2Builder?
			{
				$arr = array_merge($arr, array_slice($arg->mergeQuery(), 1));
			} else {
				$arr[] = $arg;
			}
		}

		return $arr;
	}
}