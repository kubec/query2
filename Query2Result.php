<?php

/**
 * Query2Result is a class with data manipulation methods
 *
 * @author Adam Zivner <adam.zivner@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD license
 * @package Query2
 * @link http://query2.0o.cz
 */
class Query2Result implements Iterator, Countable
{
	/** @var mysqli_result - returned by mysqli_query() */
	public $res;

	/** @var boolean - for multiple uses of fetchAll(), fetchPairs(), fetchCol() and fetchAssoc() so
	 * we don't have to call rewind() manually */
	protected $auto_rewind = false;

	public function __construct($res)
	{
		$this->res = $res;
	}

	public function __destruct()
	{
		mysqli_free_result($this->res);
	}

	/** see $auto_rewind */
	protected function checkAutoRewind()
	{
		if ($this->auto_rewind) {
			$this->rewind();
		}

		$this->auto_rewind = true;
	}

	/**
	 * Return first (or $col, if given) column of the first line of the result.
	 *
	 * @param string $col
	 * @return false|string
	 */
	public function fetchOne($col = '')
	{
		$row = mysqli_fetch_assoc($this->res);

		if (!$row) {
			return false;
		} else {
			return strlen($col) ? $row[$col] : reset($row);
		}
	}

	/**
	 * Fetch first (or $col, if given) column of all result rows.
	 *
	 * @param string $col
	 * @return array
	 */

	public function fetchCol($col = '')
	{
		$this->checkAutoRewind();

		$ret = array();

		while ($c = $this->fetchOne($col)) {
			$ret[] = $c;
		}

		return $ret;
	}

	/**
	 * Return associative array of first result row. You can specify which columns
	 * should be returned. If no columns are give, method returns all columns.
	 *
	 * @return false|array
	 */

	public function fetchRow()
	{
		$row = mysqli_fetch_assoc($this->res);

		if (!$row || func_num_args() == 0) {
			return $row;
		} else {
			$ret = array();

			foreach (is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args() as $col) {
				$ret[$col] = $row[$col];
			}

			return $ret;
		}
	}

	/**
	 * Fetch all result rows. You can specify which columns should be returned.
	 * If no columns are give, method returns all columns.
	 *
	 * @return array
	 */
	public function fetchAll()
	{
		$this->checkAutoRewind();

		$args = func_get_args();

		if (isset($args[0]) && is_array($args[0])) {
			$args = $args[0];
		}

		$ret = array();

		while ($raw_row = mysqli_fetch_assoc($this->res)) {
			$final_row = array();

			if (count($args) == 0) {
				$final_row = $raw_row;
			} else {
				foreach ($args as $col) {
					$final_row[$col] = $raw_row[$col];
				}
			}

			$ret[] = $final_row;
		}

		return $ret;
	}

	/**
	 * Return all rows associated by given column(s). See http://query2.0o.cz for details.
	 *
	 * @return array
	 */
	public function fetchAssoc()
	{
		$this->checkAutoRewind();

		$cols = func_get_args();

		$arr = $this->fetchAll();
		$ret = array();

		foreach ($arr as $row) {
			$cur =& $ret;

			foreach ($cols as $col) {
				if (!isset($cur[$row[$col]])) {
					$cur[$row[$col]] = array();
				}

				$cur =& $cur[$row[$col]];
			}

			$cur[] = $row;
		}

		return $ret;
	}

	/**
	 * Return array("id" => "jmeno", ...) consisting of the first two columns
	 * of all result rows. Useful e.g. for fetching values for select box.
	 *
	 * @param string|integer $key - which column should be used for key
	 * @param string|integer $value - which column should be used for value
	 * @return array
	 */
	public function fetchPairs($key = 0, $value = 1)
	{
		$this->checkAutoRewind();

		$ret = array();

		while ($row = mysqli_fetch_array($this->res)) {
			$ret[$row[$key]] = $row[$value];
		}

		return $ret;
	}

	public function numRows()
	{
		return mysqli_num_rows($this->res);
	}

	// Countable interface
	public function count()
	{
		return $this->numRows();
	}

	// Iterator interface

	private $idx = 0; // generate some (for each row unique) key
	private $row; // current row

	/**
	 * Rewind the result set to the start (or to the given row). It's both
	 * directly callable and implementation of Iterator interface
	 *
	 * @param integer $row - to which row result set should be rewinded
	 */

	public function rewind($row = 0)
	{
		if (mysqli_num_rows($this->res)) {
			mysqli_data_seek($this->res, 0);
		}

		$this->idx = 0;
	}

	public function current()
	{
		return $this->row;
	}

	public function key()
	{
		return $this->idx;
	}

	public function next()
	{
		$this->idx++;
	}

	public function valid()
	{
		$this->row = $this->fetchRow();

		return $this->row ? true : false;
	}
}