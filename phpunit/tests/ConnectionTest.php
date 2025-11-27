<?php

use PHPUnit\Framework\TestCase;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
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

        // $this->assertEquals(ty);
    }

    public function testMasterSlaveExec(): void
    {
        $conn = new Connection([
            'r1' => self::REPLICA1_URL,
            'r2' => self::REPLICA2_URL
        ], 'req-id');
        $data = $conn->call('select CURRENT_USER() as user', [], 'run')->fetchOne();
        $this->assertEquals($data['user'], 'root@127.0.0.1');
    }

//    public function testMasterConstructor(): void
//    {
//        $this->expectNotToPerformAssertions();
//        $baseDao = new Connection(['master' => self::MASTER_URL]);
//        $baseDao->close();
//    }
//
//    public function testMultiSlave(): void
//    {
//        $baseDao = new Connection([
//        ], [
//            'slave1' => self::REPLICA1_URL,
//            'slave2' => self::REPLICA1_URL,
//        ]);
//        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
//        $this->assertEquals($data['user'], 'replica1@%');
//        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
//        $this->assertEquals($data['user'], 'replica1@%');
//        $baseDao->close();
//    }
//
    public function testConnectionNotFound(): void
    {
        $this->expectExceptionMessage(Consts::CONNECTION_IS_EMPTY_MSG);
        $conn = new Connection([
        ], 'req-id');
        $conn->call('select CURRENT_USER() as user', [], 'run')->fetchOne();
        $conn->close();
    }


    public function testInsert(): void
    {
        $conn = new Connection([
            'm1' => self::MASTER_URL
        ], 'req-id');
        $table = 'table_for_insert';
        $conn->call('truncate table ' . $table);
//        $baseDao->insert('table_for_insert', [
//            'id1' => [1, 2],
//            'id2' => ['ff', 'ddd']
//        ]);
        $result = $conn->call('insert into ' . $table . '(id1, id2) values(:p_id1, :p_id2)', [
            'p_id1' => [1, 'i'],
            'p_id2' => 'key1',
        ]);
        $this->assertNull($result, 'insert result null');

        $data = $conn->call('select * from ' . $table)->fetchOne();

        $this->assertIsArray($data, 'is array');
        $this->assertArrayHasKey('id1', $data);
        $this->assertArrayHasKey('id2', $data);
        $this->assertTrue($data['id1'] === '1' && $data['id2'] === 'key1');
    }

//    public function testInsertException(): void
//    {
//        $this->expectException(DbException::class);
//        $baseDao = new Connection(['master' => self::MASTER_URL]);
//        $baseDao->insert('table_for_not_found', [
//            'id1' => [1, 2],
//            'id2' => ['ff', 'ddd']
//        ]);
//        // todo check data
//        $baseDao->close();
//    }
//
//    public function testDeadLockSuccess()
//    {
//        $conn1 = new Connection(['m1' => self::MASTER_URL], 'req-id');
//        $conn2 = new Connection(['m1' => self::MASTER_URL], 'req-id');
//        $conn1->call('call init_dead_lock_table(1, 2)');
//        $conn1->call('call test_dead_lock(1, 2)', [], '', MYSQLI_ASYNC);
//        $conn2->call('call test_dead_lock(2, 1)');
//        $data = $conn2->call('select * from test_deadlock_table where id in (1,2)')->fetchAll();
//        $this->assertEqualsCanonicalizing($data, [['id' => '1', 'value' => '2'], ['id' => '2', 'value' => '2']]);
//
//        $conn1->close();
//        $conn2->close();
//    }

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
//
//    public function testConnectionAutoCommitParam()
//    {
//        $baseDao = new Connection(['master' => self::MASTER_URL . '?autocommit=1']);
//        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
//        $this->assertEqualsCanonicalizing($data, ['Variable_name' => 'autocommit', 'Value' => 'ON'], 'autocommit on');
//        $baseDao->close();
//        $baseDao = new Connection(['master' => self::MASTER_URL . '?autocommit=0']);
//        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
//        $this->assertEqualsCanonicalizing(
//            $data,
//            ['Variable_name' => 'autocommit', 'Value' => 'OFF'],
//            'autocommit off'
//        );
//        $baseDao->close();
//        $baseDao = new Connection(['master' => self::MASTER_URL]);
//        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
//        $this->assertEqualsCanonicalizing(
//            $data,
//            ['Variable_name' => 'autocommit', 'Value' => 'OFF'],
//            'autocommit default'
//        );
//        $baseDao->close();
//    }
//
    public function testReconnectException()
    {
        $this->expectException(DbReconnectException::class);

        $conn1 = new Connection(['m1' => self::MASTER_URL]);
        $conn2 = new Connection(['m2' => self::MASTER_URL]);

        $conn1->autocommit(false);
        $conn1->beginTransaction();
        $conn1->call('update test_deadlock_table set val = val + 1 where id = 4');

        $conn2->call('kill ' . $conn1->threadId);
        $conn2->close();

        $conn1->call('update test_deadlock_table set val = val + 2 where id = 4');
    }

    public function testReconnect()
    {
        $conn1 = new Connection(['m1' => self::MASTER_URL]);

        try {
            $conn2 = new Connection(['m2' => self::MASTER_URL]);
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

    public function testDeadLockException()
    {
        $this->expectException(DeadlockException::class);

        $conn1 = new Connection(['m1' => self::MASTER_URL . '?deadlock-try-count=-1']);
        $conn2 = new Connection(['m2' => self::MASTER_URL . '?deadlock-try-count=-1']);

        $conn1->call('update test_deadlock_table set val = 0 where id in (2, 3)');

        $conn1->autocommit(false);
        $conn2->autocommit(false);

        $conn1->beginTransaction();
        $conn2->beginTransaction();

        $conn1->call('update test_deadlock_table set val = val + 1 where id = 4', [], 'conn1');
        $conn2->call('update test_deadlock_table set val = val + 1 where id = 3', [], 'conn2');

        $conn1->call('update test_deadlock_table set val = val + 2 where id = 3', [], '', MYSQLI_ASYNC);
        $conn2->call('update test_deadlock_table set val = val + 2 where id = 4');

        $conn1->commit();
        $conn2->commit();

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
        $fetch = $conn->call('update test_table set value = value + 1 where id = 1');
        $this->assertTrue(empty($fetch->fetchOne()), 'empty data for update');
        $this->assertEquals($fetch->getAffectedRows(), 1, 'one row update');
        $this->assertTrue($fetch->getStmt(), 'stmt true after update');

        $fetch = $conn->call('update test_table set value = 1 where id = 2');
        $this->assertEquals($fetch->getAffectedRows(), 0, 'zero row update');
        $this->assertTrue(empty($fetch->fetchAll()), 'empty data for update');

        $fetch = $conn->call('select 1 as id');
        $this->assertTrue($fetch->getStmt() instanceof \mysqli_result);
        $conn->close();
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

    public function testWrongParamException()
    {
        $this->expectException(DbException::class);
        $conn = new Connection(['m1' => self::MASTER_URL]);
        $conn->call('call not_exist_proc()');
        $conn->close();
    }

//
    public function testDuplicateException()
    {
        $this->expectException(DuplicateRowDbException::class);
        $conn = new Connection(['master' => self::MASTER_URL]);
        $conn->beginTransaction();
        $conn->call('delete from test_duplicate where id = 1');
        $conn->call('insert into test_duplicate(id) values (1)');
        $conn->call('insert into test_duplicate(id) values (1)');
        $conn->commit();
        $conn->close();
    }

    public function testWrongMethodException()
    {
        $this->expectException(DuplicateRowDbException::class);
        $connect = new Connection(['master' => self::MASTER_URL]);

        $connect->call('delete from test_duplicate where id = 2');
        $connect->beginTransaction();
        $connect->insert('test_duplicate', ['id' => [2]]);
        $connect->insert('test_duplicate', ['id' => [2]]);
        $connect->commit();
    }
//
//    public function testInsertParam()
//    {
//        $baseDao = new Connection(['master' => self::MASTER_URL]);
//
//        $baseDao->call('delete from test_insert_param');
//        $baseDao->insert('test_insert_param', [
//            'num' => [2],
//            'str' => ['test'],
//            'json' => [[1, 2, 3]],
//            'for_null' => [null],
//            'bool' => [true],
//            'date' => [new DateTime('2012-11-10 09:08:07')]
//        ]);
//        $baseDao->commit();
//        $data = $baseDao->call('select * from test_insert_param limit 1')->fetchOne();
//        $this->assertEqualsCanonicalizing($data, [
//            'num' => '2',
//            'str' => 'test',
//            'json' => '[1, 2, 3]',
//            'for_null' => null,
//            'bool' => '1',
//            'date' => '2012-11-10 09:08:07',
//        ]);
//    }
//
    public function testWrongConnection()
    {
        $this->expectException(DbConnectException::class);
        $conn = new Connection(['master' => 'mysql://root:pwd@127.0.0.1:3432/test2']);
        $conn->getMysqlRaw();
    }

    public function testCustomException()
    {
        $this->expectException(DbException::class);
        $this->expectExceptionMessage('custom-error');
        $conn = new Connection(['m1s' => self::MASTER_URL]);
        $conn->call('call test_exception()');
    }

    public function testCustomTextException()
    {
        $conn = new Connection(['m1' => self::MASTER_URL]);
        try {
            $conn->call('call test_exception()');
        } catch (DbException $ex) {
            $this->assertEquals($ex->getMessage(), 'custom-error');
            $this->assertEquals($ex->getText(), 'Error execute sql "call test_exception() #  ". Msg "custom-error".');
        }
    }
}