<?php

use PHPUnit\Framework\TestCase;
use \Wumvi\Dao\Mysql\Utils;
use Wumvi\Dao\Mysql\Exception\DbReconnectException;
use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;
use Wumvi\Dao\Mysql\Exception\DbConnectException;
use Symfony\Component\DependencyInjection\Container;
use Wumvi\Dao\Mysql\Connection;
use Wumvi\Dao\Mysql\Consts;

class ConnectionTest extends TestCase
{
    private const MASTER_URL = 'mysql://root:pwd@127.0.0.1:3432/test';
    private const REPLICA1_URL = 'mysql://root:pwd@127.0.0.1:3433/test';
    private const REPLICA2_URL = 'mysql://root:pwd@127.0.0.1:3434/test';
    private static Container $di;

    public static function setUpBeforeClass(): void
    {
        // self::$di = Builder::makeDi(Builder::PREFIX_CLI, __DIR__, true, '/../../dev/config/service.yml');
    }

    public function testConstructor(): void
    {
        $conn = new Connection([
            'r1' => self::REPLICA1_URL,
            'r2' => self::REPLICA2_URL
        ], 'req-id');
        $conn->call('select 1')->fetchOne();
        $isConnect = $conn->isConnected();
        $this->assertTrue($isConnect, 'connection is exits');
        $m1 = $conn->getMysqlRaw();
        $m2 = $conn->getMysqlRaw();
        $this->assertEquals($m1, $m2, 'the same connection');

        $mr = $conn->getMysqlRaw();
        $this->assertTrue($mr instanceof \mysqli, 'instanceof \mysqli');

        $this->assertNotEquals($conn->threadId, Consts::THREAD_ID, 'thread is exists');
        $this->assertTrue($conn->isConnected(), 'is connected');

        $conn->close();
    }

    public function testConstructorWrong(): void
    {
        $this->expectExceptionMessage('db connect wrong. Check url http:///');
        $conn = new Connection(['r1' => 'http:///'], 'req-id');
        $conn->call('select 1');
        $conn->close();
    }

    public function testMasterSlaveExec(): void
    {
        $conn = new Connection([
            'r1' => self::REPLICA1_URL,
            'r2' => self::REPLICA2_URL
        ], 'req-id');
        $data = $conn->call('select @@hostname as hostname')->fetchOne();

        $isReplica = in_array($data['hostname'], ['php.dao.mysql-replica2', 'php.dao.mysql-replica1']);
        $this->assertTrue($isReplica);
    }

    public function testConnectionNotFound(): void
    {
        $this->expectExceptionMessage(Consts::CONNECTION_IS_EMPTY_MSG);
        $conn = new Connection([], 'req-id');
        $conn->call('select CURRENT_USER() as user', [], 'run')->fetchOne();
        $conn->close();
    }

    public function testConnectionSslParam()
    {
        $conn = new Connection([
            'm1' => self::MASTER_URL . '?flag=2048'
        ], 'req-id');
        $data = $conn->call('SHOW SESSION STATUS LIKE "Ssl_cipher"')->fetchOne();
        $this->assertEqualsCanonicalizing(
            $data,
            ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_128_GCM_SHA256']
        );
        $conn->close();
    }

    public function testReconnect()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'test_deadlock_table';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table
            (
                id  int not null primary key,
                val int null
            );
            insert into test_deadlock_table (id, val)
            values (1, 0),(2, 0),(3, 0),(4, 0);        
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $conn1 = new Connection(['m1' => self::MASTER_URL]);
        $conn2 = new Connection(['m2' => self::MASTER_URL]);

        try {
            $conn1->autocommit(false);
            $conn1->beginTransaction();
            $conn1->call('update test_deadlock_table set val = val + 1 where id = 4');

            $conn2->call('kill ' . $conn1->threadId);
            $conn2->close();

            $conn1->call('update test_deadlock_table set val = val + 2 where id = 4');
            $this->fail('no reconnect');
        } catch (DbReconnectException $ex) {
            $conn1->reconnect();
            $this->assertTrue($conn1->isConnected(), 'ok');
        }
    }

    public function testAffectedRows()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $fetch = $conn->call('select 1 union select 2');
        $this->assertEquals($fetch->affectedRows, 2);

        $fetch = $conn->call('select :p_val1 union select :p_val2', ['p_val1' => 1, 'p_val2' => 2]);
        $this->assertEquals($fetch->affectedRows, 2);
    }

    public function testConvertDateToString()
    {
        $timestamp = 1678886400;
        $datetime = new DateTime("@$timestamp");

        $str = Utils::convertDateToString($datetime);
        $this->assertEquals($str, '2023-03-15 13:20:00');
    }

    public function testDeadLockException()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'test_deadlock_table';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table
            (
                id  int not null primary key,
                val int null
            );
            insert into test_deadlock_table (id, val)
            values (1, 0),(2, 0),(3, 0),(4, 0);        
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $conn1 = new Connection(['m1' => 'mysql://root:pwd@127.0.0.1:3432/test' . '?deadlock-try-count=-1']);
        $conn2 = new Connection(['m2' => 'mysql://root:pwd@127.0.0.1:3432/test' . '?deadlock-try-count=-1']);

        $conn1->autocommit(false);
        $conn2->autocommit(false);

        $conn1->beginTransaction();
        $conn2->beginTransaction();

        try {
            $conn1->call('update test_deadlock_table set val = val + 1 where id = 4', [], 'conn1');
            $conn2->call('update test_deadlock_table set val = val + 1 where id = 3', [], 'conn2');

            $conn1->call('update test_deadlock_table set val = val + 2 where id = 3', [], '', MYSQLI_ASYNC);
            usleep(200);
            $conn2->call('update test_deadlock_table set val = val + 2 where id = 4');
            $this->fail('deadlock not found');
        } catch (\Exception $ex) {
            $this->assertTrue(true, 'deadlock is found');
        }

        $mysqli1 = $conn1->getMysqlRaw();
        // Ожидание и получение результата
        $links = [$mysqli1];
        $errors = $reject = [];

        if (mysqli::poll($links, $errors, $reject, 1)) {
            foreach ($links as $link) {
                $result = $link->reap_async_query();
                if ($result instanceof \mysqli_result) {
                    $result->free();
                }
            }
        }

        $conn2->rollback();
        $conn1->rollback();

        $conn1->close();
        $conn2->close();
    }

    public function testThreadId()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $data = $conn->call('select CONNECTION_ID() as thread_id')->fetchOne();
        $this->assertEqualsCanonicalizing($data, ['thread_id' => $conn->threadId . '']);
        $conn->close();
    }

    public function testEscapeString()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $this->assertEquals($conn->escapeString("'"), "\'");
    }

    public function testFetch()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'table_for_fetch';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table (id int null, value varchar(30));
            insert into $table (id, value) values(1, 'data1');
            insert into $table (id, value) values(2, 'data2');
            insert into $table (id, value) values(3, 'data3');
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $conn = new Connection(['m1' => self::MASTER_URL]);
        $fetch = $conn->call('update table_for_fetch set value = "update1" where id = 1');
        $this->assertNull($fetch, 'empty data for update');

        $fetch = $conn->call('select 1 as id');
        $this->assertTrue($fetch->result instanceof \mysqli_result);
        $conn->close();

        $conn = new Connection(['m1' => self::MASTER_URL]);
        $fetch = $conn->call('select 1 as id');
        $this->assertEquals($fetch->fetchOne(), ['id' => 1]);
        $this->assertEquals($fetch->fetchOne(), []);

        $fetch = $conn->call('select 1 as id');
        $this->assertEquals($fetch->fetchAll(), [['id' => 1]]);
        $this->assertEquals($fetch->fetchAll(), []);

        $fetch = $conn->call('select 1 as id');
        $fetch->free();
        $fetch->free();
    }

    public function testParam()
    {
        $baseDao = new Connection(['master' => self::MASTER_URL]);
        $data = $baseDao->call('select :p_number as number, :p_string as string', [
            'p_number' => 1,
            'p_string' => 'test'
        ])->fetchOne();
        $this->assertEqualsCanonicalizing($data, ['number' => '1', 'string' => 'test']);
        $baseDao->close();
    }

    public function testProcNotFound()
    {
        $this->expectException(DbException::class);
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $conn->call('call not_exist_proc()');
        $conn->close();
    }

    public function testDuplicateException()
    {
        $this->expectException(DuplicateRowDbException::class);
        $conn = new Connection(['master' => self::MASTER_URL]);
        $table = 'test_for_duplicate';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table
            (
                id int not null primary key
            );
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $conn->beginTransaction();
        $conn->call('insert into test_for_duplicate(id) values (1)');
        $conn->call('insert into test_for_duplicate(id) values (1)');
        $conn->commit();
        $conn->close();
    }

    public function testWrongConnection()
    {
        $this->expectException(DbConnectException::class);
        $conn = new Connection(['master' => 'mysql://root:pwd@127.0.0.1:3432/test2']);
        $conn->getMysqlRaw();
    }

    public function testWrongPrepare()
    {
        $errorMsg = <<<TEXT
            Error execute sql "bad-data". Msg "You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'bad-data' at line 1"
        TEXT;
        $errorMsg = trim($errorMsg);

        $this->expectExceptionMessage($errorMsg);
        $conn = new Connection(['m' => self::MASTER_URL]);
        $conn->call('bad-data', ['p' => 1]);
    }

    public function testGetComments()
    {
        $conn = new Connection(['m' => self::MASTER_URL], 'test-request-id');
        $comments = $conn->getComment('test-comment');
        $this->assertEquals($comments, ' # test-comment test-request-id');

        $conn = new Connection(['m' => self::MASTER_URL], '');
        $comments = $conn->getComment('');
        $this->assertEquals($comments, '');
    }

    public function testPhpTypeToMysqli()
    {
        $conn = new Connection(['m' => self::MASTER_URL]);
        $type = $conn->phpTypeToMysqli(1);
        $this->assertEquals($type, 'i');

        $type = $conn->phpTypeToMysqli(1.2);
        $this->assertEquals($type, 'd');

        $type = $conn->phpTypeToMysqli(true);
        $this->assertEquals($type, 'i');

        $type = $conn->phpTypeToMysqli(null);
        $this->assertEquals($type, 's');

        $type = $conn->phpTypeToMysqli(new \stdClass());
        $this->assertEquals($type, 's');
    }

    public function testConvertArray()
    {
        $conn = new Connection(['m' => self::MASTER_URL]);

        $array = $conn->convert2Dto1D(['id', 'val'], [[1, 2], [3, 4]]);
        $this->assertEquals($array, [1, 2, 3, 4]);

        $array = $conn->convert1Dto2D(['id', 'val'], [1, 2, 3, 4]);
        $this->assertEquals($array, [[1, 2], [3, 4]]);

        try {
            $conn->convert2Dto1D(['id', 'val', 'col'], [[1, 2], [3, 4]]);
            $this->fail('exception not cached');
        } catch (DbException $ex) {
            $this->assertEquals($ex->publicMsg, 'wrong value size for insert');
        }

        try {
            $conn->convert1Dto2D(['id', 'val', 'col'], [1, 2, 3, 4]);
            $this->fail('exception not cached');
        } catch (DbException $ex) {
            $this->assertEquals($ex->publicMsg, 'wrong value size for insert');
        }
    }

    public function testCommitBad()
    {
        $this->expectException(DbException::class);
        $conn = new Connection(['m' => self::MASTER_URL]);
        $conn->beginTransaction();
        $conn->call('select 1');
        $conn->commit(3333);
        $this->assertTrue(true);
    }

    public function testRollbackBad()
    {
        $this->expectException(DbException::class);
        $conn = new Connection(['m' => self::MASTER_URL]);
        $conn->beginTransaction();
        $conn->call('select 1');
        $conn->rollback(3333);
        $this->assertTrue(true);
    }

    public function testCustomTextException()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            drop procedure if exists test_exception;
            create procedure test_exception()
            begin
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'custom-error';
            end;
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        try {
            $conn->call('call test_exception()');
        } catch (DbException $ex) {
            $this->assertEquals($ex->getMessage(), 'Error execute sql "call test_exception()". Msg "custom-error"');
            $this->assertEquals($ex->publicMsg, 'db error');
        }
    }

    public function testUpdate()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'table_for_update';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table (id int null, value varchar(30));
            insert into $table (id, value) values(1, 'data1');
            insert into $table (id, value) values(2, 'data2');
            insert into $table (id, value) values(3, 'data3');
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $affectedRows = $conn->update($table, ['value' => 'test1'], ['id' => 1]);
        $data = $mysqli->query("select * from $table")->fetch_all();
        $this->assertEquals($data, [[1, 'test1'], [2, 'data2'], [3, 'data3']]);
        $this->assertEquals($affectedRows, 1);

        $affectedRows = $conn->update($table, ['value' => 'all']);
        $data = $mysqli->query("select * from $table")->fetch_all();
        $this->assertEquals($data, [[1, 'all'], [2, 'all'], [3, 'all']]);
        $this->assertEquals($affectedRows, 3);

    }

    public function testDelete()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'table_for_delete';
        $mysqli = $conn->getMysqlRaw();
        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table (id int null, value varchar(30));
            insert into $table (id, value) values(1, 'data1');
            insert into $table (id, value) values(2, 'data2');
            insert into $table (id, value) values(3, 'data3');
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $affectedRows = $conn->delete($table, ['id' => 1]);
        $data = $mysqli->query("select * from $table where id between 2 and 3")->fetch_all();
        $this->assertEquals($data, [[2, 'data2'], [3, 'data3']]);
        $this->assertEquals($affectedRows, 1);

        $affectedRows = $conn->delete($table, []);
        $data = $mysqli->query("select * from $table")->fetch_all();
        $this->assertEquals($data, []);
        $this->assertEquals($affectedRows, 2);
    }

    public function testInsert()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'table_for_insert';
        $mysqli = $conn->getMysqlRaw();

        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table (id int null, value varchar(30));
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $conn->insertSingle($table, ['id' => 1, 'value' => 'data1']);
        $data = $conn->select(true, $table, 'value', ['id' => 1]);
        $this->assertEquals($data, ['value' => 'data1']);

        $insertId = $conn->insertSingle($table, []);
        $this->assertEquals($insertId, -1);

        $conn->insert1DBigBind($table, ['id', 'value'], [2, 'data2', 3, 'data3']);
        $data = $mysqli->query("select * from $table where id between 2 and 3")->fetch_all();
        $this->assertEquals($data, [[2, 'data2'], [3, 'data3']]);

        $conn->insert1DBigBind($table, ['id', 'value'], []);

        $conn->insert2DMultiBind($table, ['id', 'value'], [[4, 'data4'], [5, 'data5']]);
        $data = $mysqli->query("select * from $table where id between 4 and 5")->fetch_all();
        $this->assertEquals($data, [[4, 'data4'], [5, 'data5']]);

        $conn->insert2DMultiBind($table, ['id', 'value'], []);
    }

    public function testSelect()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $table = 'table_for_select';
        $mysqli = $conn->getMysqlRaw();

        $sql = <<<SQL
            DROP TABLE IF EXISTS $table;
            create table $table (id int null, value varchar(30));
            insert into $table (id, value) values(1, 'data1');
            insert into $table (id, value) values(2, 'data2');
            insert into $table (id, value) values(3, 'data3');
        SQL;
        $mysqli->multi_query($sql);
        $conn->cleanup($mysqli);

        $data = $conn->select(true, $table, 'value', ['id' => 1]);
        $this->assertEquals($data, ['value' => 'data1']);

        $data = $conn->select(true, $table, 'value');
        $this->assertEquals($data, ['value' => 'data1']);

        $data = $conn->select(false, $table, 'value');
        $this->assertEquals($data, [['value' => 'data1'], ['value' => 'data2'], ['value' => 'data3']]);

        $data = $conn->select(false, $table, [], ['id' => 4]);
        $this->assertEquals($data, []);
    }
}