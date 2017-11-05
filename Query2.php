<?php

/**
 * Query2 is a minimalist MySQL layer which can get maximum of MySQL database in minimal code
 *
 * @author Adam Zivner <adam.zivner@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD license
 * @package Query2
 * @link http://query2.0o.cz
 */

defined("QUERY2_VERSION") || define("QUERY2_VERSION", "1.2.0");

class Query2
{
	/**
	 * @var mysqli - MySQL connection
	 */
	public $con;

	/** @var callback - see setLogCallback() */
	protected $log_callback = null;

	/**
	 * Create object and optionally connect do database server and select database.
	 * If no arguments are given, we don't try to connect, if $database is empty, we
	 * don't select database. See also connect() method for details.
	 *
	 * @param string $host
	 * @param string $login
	 * @param string $password
	 * @param string $database
	 * @param boolean $new_link - enforce creation of the new link
	 * @param integer $client_flags - see mysql_connect() documentation
	 *
	 * @throws Query2Exception - codes 100 (can't connect to server), 101 (can't select database)
	 */

	public function __construct(
		$host = '',
		$login = '',
		$password = '',
		$database = '',
		$new_link = false,
		$client_flags = 0
	) {
		if (func_num_args() > 0) {
			$this->connect($host, $login, $password, $database, $new_link, $client_flags);
		}
	}

	/**
	 * @param $host
	 * @param $login
	 * @param $password
	 * @param string $database
	 * @param bool $new_link
	 * @param int $client_flags
	 * @return $this
	 * @throws Query2Exception
	 */
	public function connect($host, $login, $password, $database = '', $new_link = false, $client_flags = 0)
	{
		$attempts_left = 3;
		do {
			$this->con = mysqli_connect($host, $login, $password);
			if ($this->con) {
				break;
			}
			$attempts_left--;
			sleep(rand(4, 5)); //waiting x seconds before the next attempt
		} while (!$this->con && $attempts_left > 0);

		if (!$this->con) {
			throw new Query2Exception(100, "Can't connect to database server.");
		} else {
			if (strlen($database) && !mysqli_select_db($this->con, $database)) {
				throw new Query2Exception(101, "Can't select database.");
			}
		}

		return $this;
	}

	public function close()
	{
		if ($this->con) {
			mysqli_close($this->con);
		}

		return $this;
	}

	/**
	 * Callback is called right after execution of every query (before error exception may be raised).
	 * Function should expect 3 arguments: success (bool), query (string), elapsed time in seconds
	 *
	 * @param callback $callback
	 * @return $this
	 */
	public function setLogCallback($callback)
	{
		$this->log_callback = $callback;
		return $this;
	}

	public function getLogCallback()
	{
		return $this->log_callback;
	}

	// Transaction handling routines
	public function beginTransaction()
	{
		$this->query("SET AUTOCOMMIT=0");
		$this->query("START TRANSACTION");

		return $this;
	}

	public function commit()
	{
		$this->query("COMMIT");
		$this->query("SET AUTOCOMMIT=1");

		return $this;
	}

	public function rollBack()
	{
		$this->query("ROLLBACK");
		$this->query("SET AUTOCOMMIT=1");

		return $this;
	}

	public function affectedRows()
	{
		return mysqli_affected_rows($this->con);
	}

	public function lastInsertId()
	{
		return mysqli_insert_id($this->con);
	}

	/**
	 * Compose SQL query from given arguments. Apply modifiers etc.
	 * Used by query and pquery.
	 *
	 * @throws Query2Exception - codes 200 (wrong argument list/order) and 201 (unknown modifier)
	 * @return string - final, ready to be executed SQL query
	 */
	public function composeQuery()
	{
		return $this->composeQueryFromArray(func_get_args());
	}

	protected function composeQueryFromArray($arr)
	{
		$args = array(); // SQL chunks/arguments
		$first = $arr[0]; // first argument

		// if first argument is array, take it as a list of arguments
		foreach (is_array($first) ? $first : $arr as $arg) {
			if (is_object($arg) && method_exists($arg, 'mergeQuery')) // is this Query2Builder?
			{
				$args = array_merge($args, $arg->mergeQuery());
			} else {
				$args[] = $arg;
			}
		}

		$sql = "";

		// Now, let's deal with modifiers

		for ($i = 0, $argc = count($args); $i < $argc; $i++) {
			$chunk = $args[$i];

			if (!is_string($chunk)) {
				throw new Query2Exception(200,
					'Wrong argument/modifier order to query() function: ' . var_export($args, true));
			}

			// find modifiers
			preg_match_all("/%(?:%|([a-zA-Z]*)(?![a-zA-Z]))/", $chunk, $matches, PREG_SET_ORDER);

			foreach ($matches as $m) {
				if ($m[0] == '%%') {
					$arg = '%';
				} else {
					$arg = $args[++$i];

					if ($arg === null) {
						$arg = 'NULL';
					} else {
						if ($m[1] == 'i') // integer
						{
							$arg = (int)$arg;
						} else {
							if ($m[1] == 'f') // float
							{
								$arg = (float)$arg;
							} else {
								if (strtolower($m[1]) == 's') // string
								{
									$arg = "'" . ($m[1] == 's' ? $this->escape($arg) : $arg) . "'";
								} else {
									if (strtolower($m[1]) == 't') // table or column name
									{
										$arg = $this->escapeTableName($arg, $m[1] == 't');
									} else {
										if (strtolower($m[1]) == 'x') // insert pure SQL query, no enclosing, optionally escaping
										{
											$arg = $m[1] == 'x' ? $this->escape($arg) : $arg;
										} else {
											if (strtolower($m[1]) == 'in' || strtolower($m[1]) == 'nin') // inserts IN ('a', 'b', 'c')
											{
												$arg = $this->in($arg, strtolower($m[1]) == 'in',
													$m[1] == 'in' || $m[1] == 'nin');
											} else {
												if (strtolower($m[1]) == 'a') // update modifier, returns name='John', surname='Black'
												{
													$arg = $this->assocUpdate($arg, $m[1] == 'a');
												} else {
													if (strtolower($m[1]) == 'v') // insert modifier, returns (name, surname) VALUES ('John', 'Black')
													{
														$arg = $this->assocInsert($arg, $m[1] == 'v');
													} else {
														if (strtolower($m[1]) == 'va') // INSERT ... ON DUPLICATE KEY UPDATE
														{
															$arg = $this->assocInsertUpdate($arg, $m[1] == 'va');
														} else {
															throw new Query2Exception(201,
																"Unknown modifier '$m[1]' to query() function.");
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}

				$chunk = $this->str_replace_once($m[0], $arg, $chunk);
			}

			$sql .= $chunk . ' ';
		}

		return trim($sql);
	}

	/**
	 * Main Query function. Query is composed by helper function composeQuery()
	 *
	 * Arguments can be:
	 * - chunks of SQL (with modifiers)
	 * - modifier arguments
	 * - instances of Query2Builder
	 * - an array as first argument containing anything from this list
	 *
	 * @throws Query2Exception - same as exec()
	 * @return Query2Result|Query2 - Query2Result for SELECT, DESCRIBE etc. and Query2 for INSERT, UPDATE etc.
	 */

	public function query()
	{
		return $this->exec(func_get_args(), "mysql_query");
	}

	/**
	 * Executes unbuffered query, otherwise identical to query()
	 *
	 * @throws Query2Exception - same as exec()
	 * @return Query2Result|Query2 - Query2Result for SELECT, DESCRIBE etc. and Query2 for INSERT, UPDATE etc.
	 */

	public function uquery()
	{
		return $this->exec(func_get_args(), "mysql_unbuffered_query");
	}

	/**
	 * Inner query execution method.
	 *
	 * @param array $args - array of args given to query() or uquery()
	 * @param string $query_function - either "mysql_query" or "mysql_unbuffered_query"
	 *
	 * @throws Query2Exception - code 300 (MySQL error) and the same one as composeQuery()
	 * @return Query2Result|Query2
	 */

	protected function exec($args, $query_function)
	{
		// get pure SQL with composeQueryArray
		$sql = $this->composeQueryFromArray($args);

		$started_time = microtime(true); // we'll measure how much time execution of a query took

		// here we go!
		if ($query_function == "mysql_query") {
			$attempts_left = 5;
			do {
				$res = mysqli_query($this->con, $sql);
				// if we found a deadlock, we will try this query 4 more times
				if (mysqli_errno($this->con) == 1213) { // 1213 - deadlock error
					$deadlocked = true;
					$attempts_left--;
					sleep(rand(3,10)); //waiting x seconds before the next attempt
				} else {
					$deadlocked = false;
				}
			} while ($deadlocked && $attempts_left > 0);
		} else {
			if ($query_function == "mysql_unbuffered_query") {
				$res = mysqli_query($this->con, $sql, MYSQLI_USE_RESULT);
			} else {
				throw new Query2Exception("Internal error: unknown query function.");
			}
		}

		if ($this->log_callback) // calling the callback (useful for logging, performance tuning etc.)
		{
			call_user_func($this->log_callback, !mysqli_error($this->con), $sql, microtime(true) - $started_time);
		}

		if (mysqli_error($this->con)) // oops, query failed
		{
			throw new Query2Exception(300, mysqli_error($this->con), $sql);
		}

		return ($res instanceof mysqli_result) ? new Query2Result($res) : $this;
	}

	/**
	 * Prints query and then executes it. It's intended for in-place use instead
	 * of query for debugging, just replace "query(" with "pquery(".
	 *
	 * If you just want to get query string (e.g. to write to file), use composeQuery()
	 *
	 * @throws Query2Exception - same codes as query()
	 * @return Query2Result|Query2
	 */

	public function pquery()
	{
		$args = func_get_args();
		echo $this->composeQueryFromArray($args);
		return call_user_func_array(array($this, "query"), $args);
	}

	/**
	 * Execute multiple queries either from string or stream. It can handle only
	 * the most basic form - it expects that ; as the last nonwhite character on the
	 * line signals the end of the query. It works with exports from phpMyAdmin and similar tools
	 * just fine. This method is different from uquery() and query() methods in that it
	 * doesn't expect modifiers.
	 *
	 * @param string|stream $h - either actual SQL queries or a file handle (stream) in which they are stored
	 * @return integer - number of executed queries
	 */

	public function mquery($h)
	{
		if (!is_resource($h)) // if it's a string create "string resource"
		{
			$h = fopen('data://text/plain,' . $h, 'r');
		}

		$query_count = 0;

		for ($line = $sql = ''; $line !== false; $sql .= $line = fgets($h)) {
			if (substr(rtrim($line), -1) == ';') {
				$this->query("%X", $sql);
				$sql = '';
				$query_count++;
			}
		}

		if (trim($sql) != '') { // last query does not have to be trailed by ;
			$this->query("%X", $sql);
			$query_count++;
		}

		fclose($h);
		return $query_count;
	}

	/** Factory method */
	public function builder()
	{
		return new Query2Builder();
	}

	/**
	 * Factory method
	 * @param $statement
	 * @return Query2Statement
	 */
	public function statement($statement)
	{
		return new Query2Statement($statement);
	}

	/**
	 * Escape schema object names. ` is escaped by doubling it, ' and " are not because the don't have to be escaped.
	 * E.g. table.column => `table`.`column`
	 *
	 * @param string $str
	 * @param boolean $escape
	 * @return string
	 */

	public function escapeTableName($str, $escape)
	{
		if ($escape) {
			$str = str_replace("`", "``", $str);
		}

		$arr = explode(".", $str);

		return "`" . implode("`.`", $arr) . "`";
	}

	/**
	 * @param string $str
	 * @return string
	 */

	public function escape($str)
	{
		return mysqli_real_escape_string($this->con, $str);
	}

	/**
	 * Escapes value according to its type and $escape
	 *
	 * @param mixed $v
	 * @param boolean $escape
	 * @return string
	 */

	protected function escapeValue($v, $escape)
	{
		if ($v === null) {
			return "NULL";
		} else {
			if (is_object($v) && get_class($v) == "Query2Statement") {
				return $v->statement;
			} else {
				return "'" . ($escape ? $this->escape($v) : $v) . "'";
			}
		}
	}

	/**
	 * Generate (NOT) IN('a', 'b', 'c') from given array. It works also with an empty array too.
	 *
	 * @param array $arr
	 * @param boolean $in - is it IN or NOT IN?
	 * @param boolean $escape
	 * @return string - either "FALSE" or "IN(...)"
	 */

	protected function in($arr, $in, $escape)
	{
		if (count($arr)) {
			foreach ($arr as $key => $value) {
				$arr[$key] = $this->escapeValue($value, $escape);
			}

			return ($in ? '' : 'NOT ') . 'IN (' . implode(", ", $arr) . ')';
		} else {
			return $in ? 'AND FALSE' : 'IS NOT NULL';
		}
	}

	/**
	 * Transforms associative array into the UPDATE form
	 * name = 'John', surname = 'Black', age = '33'
	 *
	 * @param array $assoc
	 * @param boolean $escape - escape contents?
	 * @return string
	 */
	protected function assocUpdate($assoc, $escape)
	{
		$arr = array();

		foreach ($assoc as $key => $value) {
			$arr[] = "`" . $this->escape($key) . "` = " . $this->escapeValue($value, $escape);
		}

		return implode(", ", $arr);
	}

	/**
	 * Transforms associative array into the INSERT INTO form:
	 * (name, surname, age) VALUES ('John', 'Black', '33')
	 *
	 * @params array $assoc
	 * @params boolean $escape - escape contents?
	 * @return string
	 */

	protected function assocInsert($arr, $escape)
	{
		$vars = $ret = array();

		foreach ((isset($arr[0]) && is_array($arr[0])) ? $arr : array($arr) as $assoc) {
			$vars = $values = array();

			foreach ($assoc as $key => $value) {
				$vars[] = "`" . $this->escape($key) . "`";

				$values[] = $this->escapeValue($value, $escape);
			}

			$ret[] = "(" . implode(", ", $values) . ")";
		}

		return "(" . implode(", ", $vars) . ") VALUES " . implode(", ", $ret);
	}

	/**
	 * This method implements useful INSERT ... ON DUPLICATE KEY UPDATE.
	 * There are two ways to use this function, "simple" and "complex".
	 * See http://query2.0o.cz for explanation.
	 *
	 * @param array $arr - two possibilities - see documentation
	 * @param boolean $escape - escape content?
	 * @return string
	 */

	protected function assocInsertUpdate($arr, $escape)
	{
		$complex = isset($arr["data"]) && is_array($arr["data"]);
		$update = ($complex && isset($arr["update"])) ? $arr["update"] : array_keys($complex ? $arr["data"] : $arr);

		foreach ($update as $idx => $name) // we'll do it in-place
		{
			$update[$idx] = "`$name` = VALUES(`$name`)";
		}

		if ($complex && isset($arr["auto_increment"])) {
			$update[] = "`$arr[auto_increment]` = LAST_INSERT_ID(`$arr[auto_increment]`)";
		}

		return $this->assocInsert($complex ? $arr["data"] : $arr, $escape) . " ON DUPLICATE KEY UPDATE " . implode(", ",
				$update);
	}

	protected function str_replace_once($needle, $replace, $haystack)
	{
		$pos = strpos($haystack, $needle);

		return $pos === false ? $haystack : substr_replace($haystack, $replace, $pos, strlen($needle));
	}
}

class Query2Exception extends Exception
{
	public $code, $error, $sql;

	public function __construct($code, $error, $sql = "")
	{
		$this->code = $code; // error code (e.g. 300)
		$this->error = $error;
		$this->sql = $sql;

		parent::__construct($code . " " . $error . (strlen($sql) ? ": $sql" : ""), $code);
	}
}

/**
 * QueryStatement provides a way to use unescaped value like (MySQL NOW()) in
 * otherwise escaped role (in %v, %V and %a, %A).
 */
class Query2Statement
{
	public $statement;

	public function __construct($statement)
	{
		$this->statement = $statement;
	}
}