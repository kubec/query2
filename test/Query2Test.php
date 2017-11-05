<?php
/**
 * PHPUnit test class for Query2 (all three classes). Tests need an empty database,
 * connection settings can be set in setUp() method.
 *
 * @author Adam Zivner <adam.zivner@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD license
 * @package Query2
 * @link http://query2.0o.cz
 */

require_once 'PHPUnit/Framework.php';

require_once dirname(__FILE__).'/../Query2.php';
require_once dirname(__FILE__).'/../Query2Result.php';
require_once dirname(__FILE__).'/../Query2Builder.php';

error_reporting(E_ALL);

class Query2Test extends PHPUnit_Framework_TestCase {
	/** @var Query2 */
	protected $object;

	/** Sets up the fixture. This method is called before a test is executed. */
	protected function setUp()
	{
		$this->object = new Query2;
		$this->connect();

		$this->object->query("DROP TABLE IF EXISTS test");
		$this->object->query("CREATE TABLE test (id_test INT NOT NULL AUTO_INCREMENT PRIMARY KEY, value TEXT, value2 TEXT) ENGINE=InnoDB");
	}

	protected function connect()
	{
		$this->object->connect("localhost", "root", "", "query2-ut");
	}

	public function testClose()
	{
		$this->object->close();
		$this->connect(); // reopen the connection
	}

	protected $log = array();

	public function logCallback($error, $query, $time)
	{
		$this->log[] = array($error, $query, $time);
	}

	public function testSetLogCallback()
	{
		$callback = array($this, "logCallback");

		$this->object->setLogCallback($callback);

		$this->assertEquals($this->object->getLogCallback(), $callback);

		$this->log = array();

		$this->object->query("SELECT 1");

		$this->assertEquals(count($this->log), 1);
		$this->assertEquals($this->log[0][0], true);
		$this->assertEquals($this->log[0][1], "SELECT 1");
	}

	public function testBeginTransaction()
	{
		$this->object->query("DELETE FROM test"); // ensure that table "test" is empty

		// first, let's try commiting one row insert
		$this->object->beginTransaction();
		$this->object->query("INSERT INTO test () VALUES ()");
		$this->object->commit();

		// we commited the transaction, so there should be one row
		$cnt = $this->object->query("SELECT * FROM test")->numRows();
		$this->assertEquals($cnt, 1);

		$this->object->query("DELETE FROM test"); // clean after first test

		$this->object->beginTransaction();
		$this->object->query("INSERT INTO test () VALUES ()");
		$this->object->rollBack();

		// insert has been rollbacked, so no rows now
		$cnt = $this->object->query("SELECT * FROM test")->numRows();
		$this->assertEquals($cnt, 0);
	}

	public function testAffectedRows()
	{
		$affected_rows = $this->object->query("INSERT INTO test () VALUES ()")->affectedRows();

		$this->assertEquals($affected_rows, 1);
	}

	public function testLastInsertId()
	{
		$insert_id = $this->object->query("INSERT INTO test () VALUES ()")->lastInsertId();

		$this->assertNotEquals($insert_id, false);
	}

	public function testComposeQuery()
	{
		// first very simple test with one modifier
		$str = $this->object->composeQuery("SELECT * FROM test WHERE id_test = %i", 1);
		$this->assertEquals($str, "SELECT * FROM test WHERE id_test = 1");

		// test inserting AND FALSE into IN() statement if given array is empty
		$this->assertEquals($this->object->composeQuery("%in", array()), "AND FALSE");

		// test inserting AND FALSE into IN() statement if given array is empty
		$this->assertEquals($this->object->composeQuery("%nin", array()), "IS NOT NULL");

		// test escaping values in IN() statement
		$this->assertEquals($this->object->composeQuery("%in", array("A'A", 5)), "IN ('A\'A', '5')");

		// test not-escaping values in IN() statement using %In or %IN
		$this->assertEquals($this->object->composeQuery("%IN", array("A'A", 5)), "IN ('A'A', '5')");

		// testing schema object name modifier
		$this->assertEquals($this->object->composeQuery("%t", "A'\"`A"), "`A'\"``A`");
		$this->assertEquals($this->object->composeQuery("%t", "table.column"), "`table`.`column`");

		// testing non-escaping name modifier
		$this->assertEquals($this->object->composeQuery("%T", "tab`le.column"), "`tab`le`.`column`");

		// test the %x modifier (insert pure SQL), we want to test behavior with modifiers
		$this->assertEquals($this->object->composeQuery("%x", "%s ' %"), "%s \' %");
		$this->assertEquals($this->object->composeQuery("%X", "%s ' %"), "%s ' %");

		// Test that %% is correctly substituted into %
		$this->assertEquals($this->object->composeQuery("SELECT '%%'"), "SELECT '%'");

		// Now two %
		$this->assertEquals($this->object->composeQuery("SELECT '%%%%'"), "SELECT '%%'");

		// Fifth % is a modifier
		$this->assertEquals($this->object->composeQuery("SELECT '%%%%%i'", 5), "SELECT '%%5'");
		
		// now, let's test "unknown modifier behavior" - we expect exception
		try {
			$this->object->query("SELECT * FROM test WHERE id_test = %nonexistantmodifier", 1);
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);

		$builder = $this->object->builder();
		$builder->from("test")->whereAnd("0 = 1");

		$query = $this->object->composeQuery("DELETE", $builder);
		$this->assertEquals($query, "DELETE FROM test WHERE ( 0 = 1 )");

		// Check that some special characters work correctly
		// Some PHP functions (preg_*) can mess them so we must avoid using them ...

		$content = '\u0000 \r \\n AAA';

		$ret = $this->object->composeQuery("SELECT %s", $content);

		$this->assertEquals("SELECT '".mysqli_real_escape_string($this->object->con, $content)."'", $ret);
   	}

	public function testQuery()
	{
		// let's make sure table is empty
		$this->object->query("DELETE FROM test");

		// insert two rows
		$this->object->query("INSERT INTO test %v", array(
			array("value" => "abc"),
			array("value" => "def")
		));

		// Check that two rows are identical to what we inserted
		$cols = $this->object->query("SELECT value FROM test ORDER BY id_test")->fetchCol();

		$this->assertEquals($cols[0], "abc");
		$this->assertEquals($cols[1], "def");

		// Again, test row content but now with different method
		$res = $this->object->query("SELECT value FROM test ORDER BY id_test");

		$this->assertEquals($res->fetchOne(), "abc");
		$this->assertEquals($res->fetchOne("value"), "def");

		// Test different style of giving arguments, i.e. in one array:
		$res = $this->object->query(array("SELECT value", "FROM test", "WHERE value = %s", "abc"));
		$this->assertEquals($res->fetchOne(), "abc");

		// Test iterator interface

		$res = $this->object->query("SELECT value FROM test ORDER BY id_test");

		$this->assertEquals(count($res), 2); // test countable interface

		// Test that we can rewind the result set
		for($i = 0; $i < 2; $i++) {
			foreach($res as $idx => $row)
				if($idx == 0)
					$this->assertEquals($row["value"], "abc");
				else
					$this->assertEquals($row["value"], "def");
		}

		$res = $this->object->query("SELECT * FROM test");

		$this->assertEquals(count($res->fetchAll()), 2);
		$this->assertEquals(count($res->fetchPairs()), 2);
		$this->assertEquals(count($res->fetchAssoc("value")), 2);
		$this->assertEquals(count($res->fetchCol()), 2);

		// Test, that Query2Statement works as expected (i.e. not escaping, not enclosing with ', used for e.g. NOW())
		$this->object->query("DELETE FROM test");

		try {
			// abc string will not be enclosed with ' which should result in MySQL error (=> exception)
			$this->object->query("INSERT INTO test %v", array(
				array("value" => new Query2Statement('abc')),
				array("value" => "def")
			));
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);

		// Test that string is not escaped (but it's enclosed with ')
		try {
			$this->object->query("INSERT INTO test %V", array("value" => "d'ef"));
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);

		$this->object->query("DELETE FROM test");

		// First, create some random row
		$id = $this->object->query("INSERT INTO test %V", array(
			"value" => "def",
			"value2" => "def"
		))->lastInsertId();

		// Lets try to update it
		$this->object->query("UPDATE test SET %a", array("value" => "xyz"));

		// Check the content
		$this->assertEquals("xyz", $this->object->query("SELECT value FROM test")->fetchOne());

		// Check if fetchPairs() method works
		$pairs = $this->object->query("SELECT id_test, value FROM test")->fetchPairs();

		foreach($pairs as $id_test => $value) {
			$this->assertEquals($id, $id_test);
			$this->assertEquals($value, "xyz");
		}

		// Check fetchPairs() method with arguments

		$pairs = $this->object->query("SELECT * FROM test")->fetchPairs("value", "value2");

		foreach($pairs as $value => $value2) {
			$this->assertEquals($value, "xyz");
			$this->assertEquals($value2, "def");
		}

		// test fetchRow() with arguments

		$row = $this->object->query("SELECT * FROM test")->fetchRow("value", "value2");

		$this->assertEquals(count($row), 2);
		$this->assertEquals($row["value"], "xyz");
		$this->assertEquals($row["value2"], "def");

		// test fetchAll() with arguments

		$arr = $this->object->query("SELECT * FROM test")->fetchAll("value", "value2");

		$this->assertEquals(count($arr), 1);

		$row = $arr[0];

		$this->assertEquals(count($row), 2);
		$this->assertEquals($row["value"], "xyz");
		$this->assertEquals($row["value2"], "def");

		// Check not escaping update modifier
		try {
			$this->object->query("UPDATE test SET %A", array("value" => "x'yz"));
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);

		$this->object->query("DELETE FROM test");

		// Checking fetchAssoc method
		$this->object->query("INSERT INTO test %v", array(
			array("value" => "1", "value2" => "1"),
			array("value" => "1", "value2" => "1"),
			array("value" => "1", "value2" => "2"),
			array("value" => "2", "value2" => "2")
		));

		$assoc = $this->object->query("SELECT * FROM test ORDER BY id_test")->fetchAssoc("value", "value2");

		$this->assertEquals(count($assoc[1]), 2);
		$this->assertEquals(count($assoc[1][1]), 2);
		$this->assertEquals(count($assoc[1][2]), 1);
		$this->assertEquals(count($assoc[2]), 1);
		$this->assertEquals(count($assoc[2][2]), 1);
		$this->assertEquals($assoc[2][2][0]["value"], 2);

		// numRows()
		$num_rows = $this->object->query("SELECT * FROM test ORDER BY id_test")->numRows();
		$this->assertEquals($num_rows, 4);

		// INSERT ... ON DUPLICATE KEY UPDATE

		$this->object->query("DELETE FROM test");

		$id1 = $this->object->query("INSERT INTO test %v", array("value" => "1", "value2" => "1"))->lastInsertId();
		$id2 = $this->object->query("INSERT INTO test %v", array("value" => "2", "value2" => "2"))->lastInsertId();

		$returned = $this->object->query("INSERT INTO test %va", array("id_test" => $id1, "value" => "3", "value2" => "3"))->lastInsertId();

		$row = $this->object->query("SELECT * FROM test WHERE id_test = %i", $id1)->fetchRow();

		$this->assertEquals($row["value"], 3);
		$this->assertEquals($row["value2"], 3);

		// Complex insert update syntax, now we're just updating an old row

		$returned = $this->object->query("INSERT INTO test %va", array(
			"data" => array("id_test" => $id2, "value" => "4", "value2" => "4"),
			"auto_increment" => "id_test",
			"update" => array("value")
		))->lastInsertId();

		$this->assertEquals($returned, $id2);

		$row = $this->object->query("SELECT * FROM test WHERE id_test = %i", $id2)->fetchRow();

		$this->assertEquals($row["value"], 4);
		$this->assertEquals($row["value2"], 2);

		// Other combination of insert update syntax, now insert new row

		$newid = $this->object->query("INSERT INTO test %va", array(
			"data" => array("value" => "111", "value2" => "222"),
			"auto_increment" => "id_test"
		))->lastInsertId();

		$this->assertNotEquals($newid, false);

		$row = $this->object->query("SELECT * FROM test WHERE id_test = %i", $newid)->fetchRow();

		$this->assertEquals($row["value"], 111);
		$this->assertEquals($row["value2"], 222);

		// "data" in complex syntax must be defined and must be an array, otherwise
		// argument is treated as in simple syntax which will cause an exception

		try {
			$this->object->query("INSERT INTO test %va", array("auto_increment" => "id_test"));
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);

		// query builder

		$this->object->query("DELETE FROM test");

		$this->object->query("INSERT INTO test %v", array(
			array("value" => "1", "value2" => "1"),
			array("value" => "1", "value2" => "2"),
			array("value" => "1", "value2" => "3"),
			array("value" => "2", "value2" => "4")
		));

		// IN and NOT IN

		$arr = $this->object->query("SELECT * FROM test WHERE value2 %in", array(1, 2, 3));
		$this->assertEquals(count($arr), 3);

		$arr = $this->object->query("SELECT * FROM test WHERE value2 %nin", array(1, 2, 3));
		$this->assertEquals(count($arr), 1);

		$arr = $this->object->query("SELECT * FROM test WHERE value2 %in", array());
		$this->assertEquals(count($arr), 0);

		$arr = $this->object->query("SELECT * FROM test WHERE value2 %nin", array());
		$this->assertEquals(count($arr), 4);

		$builder = $this->object->builder()->select("test1.value2 AS t1value2")->from("test AS test1")->whereAnd("value = %i", 1)->orderBy("t1value2 DESC");
		$result = $this->object->query($builder)->fetchCol();

		$this->assertEquals(count($result), 3);

		$this->assertEquals($result[0], 3);
		$this->assertEquals($result[1], 2);
		$this->assertEquals($result[2], 1);

		$builder->clearSelect()->select("COUNT(*) AS count")->select("value")->clearFrom()->from("test")->clearWhere()->clearGroupBy()->groupBy("value")->clearOrderBy()->orderBy("count DESC");
		$result = $this->object->query($builder)->fetchAll();

		$this->assertEquals(count($result), 2);

		$this->assertEquals($result[0]["count"], 3);
		$this->assertEquals($result[0]["value"], 1);

		$this->assertEquals($result[1]["count"], 1);
		$this->assertEquals($result[1]["value"], 2);

		$builder = $this->object->builder()->
				select("t1.value AS t1value")->
				select("t2.value AS t2value")->
				select("t1.value2 AS t1value2")->
				select("t2.value2 AS t2value2")->
				from("test AS t1")->
				from("JOIN test AS t2 ON t2.value2 = t1.value")->
				orderBy("t1value DESC")->
				limit(1);

		$result = $this->object->query($builder)->fetchAll();

		$this->assertEquals(count($result), 1);

		$this->assertEquals($result[0]["t1value"], 2);
		$this->assertEquals($result[0]["t1value2"], 4);
		$this->assertEquals($result[0]["t2value"], 1);
		$this->assertEquals($result[0]["t2value2"], 2);

		// Same query, but now with LIMIT 1, 1

		$result = $this->object->query($builder->limit(1, 1))->fetchAll();

		$this->assertEquals(count($result), 1);

		$this->assertEquals($result[0]["t1value"], 1);
		$this->assertEquals($result[0]["t1value2"], 1);
		$this->assertEquals($result[0]["t2value"], 1);
		$this->assertEquals($result[0]["t2value2"], 1);

		// Same query, no limit

		$result = $this->object->query($builder->clearLimit())->fetchAll();
		$this->assertEquals(count($result), 4);

		// Nested where

		$or = $this->object->builder()->whereOr("value2 = %i", 1)->whereOr("value2 = %i", 2);
		$builder = $this->object->builder()->select("*")->from("test")->whereAnd("value = %i", 1)->whereAnd($or)->orderBy("value2");
		$result = $this->object->query($builder)->fetchAll();
		
		$this->assertEquals(count($result), 2);

		$this->assertEquals($result[0]["value"], 1);
		$this->assertEquals($result[0]["value2"], 1);

		$this->assertEquals($result[1]["value"], 1);
		$this->assertEquals($result[1]["value2"], 2);

		// Nested having

		$and = $this->object->builder()->havingAnd("num >= %i", 3)->havingAnd("valueX > %i", 4);
		$builder = $this->object->builder()->select("COUNT(*) AS num")->select("SUM(value2) AS valueX")->from("test")->groupBy("value")->havingOr($and);
		$result = $this->object->query($builder)->fetchAll();

		$this->assertEquals(count($result), 1);

		$this->assertEquals($result[0]["num"], 3);
		$this->assertEquals($result[0]["valueX"], 6);

		// Same query, different HAVING and GROUP BY

		$builder->clearHaving()->clearGroupBy();
		$builder->groupBy("(value2 DIV 2)")->havingOr("valueX = %i", 4)->havingOr("num = %i", 2)->orderBy("value2 DESC");

		$result = $this->object->query($builder)->fetchAll();

		print_r($result);

		$this->assertEquals($result[0]["num"], 1);
		$this->assertEquals($result[0]["valueX"], 4);

		$this->assertEquals($result[1]["num"], 2);
		$this->assertEquals($result[1]["valueX"], 5);

		$this->assertEquals(count($result), 2);
	}

	public function testUquery()
	{
		$this->object->query("DELETE FROM test");

		$this->object->query("INSERT INTO test %v", array(
			array("value" => "1", "value2" => "1"),
			array("value" => "1", "value2" => "2")
		));

		// test that mysql_unbuffered_query was used, which implies that rewinding
		// the resource (with mysql_data_seek) is not possible.
		$res = $this->object->uquery("SELECT * FROM test");

		$i = 0;

		foreach($res as $row) $i++;
		foreach($res as $row) $i++;

		$this->assertEquals($i, 2);
	}

	public function testPquery()
	{
		ob_start();

		$this->object->pquery("SELECT * FROM test WHERE id_test = %i", 1);

		$this->assertEquals(ob_get_contents(), "SELECT * FROM test WHERE id_test = 1");

		ob_end_clean();
	}

	public function testMquery()
	{
		$this->object->query("DELETE FROM test");

		$this->object->mquery("
INSERT INTO test (value, value2) VALUES ('1', '2');
INSERT INTO test (value, value2) VALUES ('3', '4');
");

		$count = $this->object->query("SELECT COUNT(*) FROM test")->fetchOne();

		$this->assertEquals($count, 2);

		// Now, we'll try unorthodox but still valid SQL which this method can't handle
		try {
			$this->object->mquery("
INSERT INTO test (value, value2) VALUES ('1', '2'); INSERT INTO test (value, value2) VALUES ('3', '4');
");
		}
		catch(Query2Exception $e) {
			$exception_caught = true;
		}

		if(!isset($exception_caught))
			$this->fail("Expected exception was not raised!");

		unset($exception_caught);
	}
}