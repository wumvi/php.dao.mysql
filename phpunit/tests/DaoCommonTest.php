<?php

use PHPUnit\Framework\TestCase;
use Wumvi\Dao\Mysql\BaseDao;
use Wumvi\Dao\Mysql\Consts;
use Wumvi\Dao\Mysql\Exception\DbConnectException;
use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;

class DaoCommonTest extends TestCase
{
    private const MASTER_URL = 'mysql://root:123@mysqltest:3311/test';
    private const SLAVE_URL = 'mysql://replica1:123@mysqltest:3311/test';

    public function testMasterConstructor(): void
    {
        $this->expectNotToPerformAssertions();
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao->close();
    }

    public function testMasterSlaveExec(): void
    {
        $baseDao = new BaseDao([
            'master' => self::MASTER_URL,
        ], [
            'slave1' => self::SLAVE_URL,
        ]);
        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'replica1@%');

        $data = $baseDao->call('select CURRENT_USER() as user', [], false, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'root@%');
        $baseDao->close();
    }

    public function testMultiSlave(): void
    {
        $baseDao = new BaseDao([
        ], [
            'slave1' => self::SLAVE_URL,
            'slave2' => self::SLAVE_URL,
        ]);
        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'replica1@%');
        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'replica1@%');
        $baseDao->close();
    }

    public function testConnectionNotFound(): void
    {
        $this->expectExceptionMessage(Consts::CONNECTION_IS_EMPTY_MSG);
        $baseDao = new BaseDao([], []);
        $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $baseDao->close();
    }

    public function testGetRawMysql(): void
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $mysql = $baseDao->getMysql(true);
        $this->assertTrue($mysql instanceof \mysqli, 'is mysql');
        $baseDao->close();
    }

    public function testInsert(): void
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $table = 'table_for_insert';
        $baseDao->call('truncate table ' . $table);
        $baseDao->insert('table_for_insert', [
            'id1' => [1, 2],
            'id2' => ['ff', 'ddd']
        ]);

        $data = $baseDao->call('select * from ' . $table)->fetchOne();
        $this->assertIsArray($data, 'is array');
        $this->assertArrayHasKey('id1', $data);
        $this->assertArrayHasKey('id2', $data);
        $this->assertTrue($data['id1'] === '1' && $data['id2'] === 'ff');
    }

    public function testInsertException(): void
    {
        $this->expectException(DbException::class);
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao->insert('table_for_not_found', [
            'id1' => [1, 2],
            'id2' => ['ff', 'ddd']
        ]);
        // todo check data
        $baseDao->close();
    }

    public function testConnectionSslParam()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL . '?flag=2048']);
        $data = $baseDao->call('SHOW SESSION STATUS LIKE "Ssl_cipher"')->fetchOne();
        $this->assertEqualsCanonicalizing(
            $data,
            ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']
        );
        $baseDao->close();
    }

    public function testConnectionAutoCommitParam()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL . '?autocommit=1']);
        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
        $this->assertEqualsCanonicalizing($data, ['Variable_name' => 'autocommit', 'Value' => 'ON'], 'autocommit on');
        $baseDao->close();
        $baseDao = new BaseDao(['master' => self::MASTER_URL . '?autocommit=0']);
        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
        $this->assertEqualsCanonicalizing(
            $data,
            ['Variable_name' => 'autocommit', 'Value' => 'OFF'],
            'autocommit off'
        );
        $baseDao->close();
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $data = $baseDao->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
        $this->assertEqualsCanonicalizing(
            $data,
            ['Variable_name' => 'autocommit', 'Value' => 'OFF'],
            'autocommit default'
        );
        $baseDao->close();
    }

    public function testDeadLockSuccess()
    {
        $baseDao1 = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao2 = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao1->call('call init_dead_lock_table(1, 2)');
        $baseDao1->call('call test_dead_lock(1, 2)', [], false, '', MYSQLI_ASYNC);
        $baseDao2->call('call test_dead_lock(2, 1)');
        $data = $baseDao2->call('select * from test_deadlock_table where id in (1,2)')->fetchAll();
        $this->assertEqualsCanonicalizing($data, [['id' => '1', 'value' => '2'], ['id' => '2', 'value' => '2']]);
        $baseDao1->close();
        $baseDao2->close();
    }

    public function testDeadLockException()
    {
        $this->expectException(DeadlockException::class);

        $baseDao1 = new BaseDao(['master' => self::MASTER_URL . '?deadlock-try-count=1']);
        $baseDao2 = new BaseDao(['master' => self::MASTER_URL . '?deadlock-try-count=1']);
        $baseDao1->call('call init_dead_lock_table(3, 4)');
        $baseDao1->call('call test_dead_lock(3, 4)', [], false, '', MYSQLI_ASYNC);
        $baseDao2->call('call test_dead_lock(4, 3)');
    }

    public function testThreadId()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $data = $baseDao->call('select CONNECTION_ID() as thread_id')->fetchOne();
        $threadId = $baseDao->getThreadId(false);
        $this->assertEqualsCanonicalizing($data, ['thread_id' => $threadId . '']);
        $baseDao->close();
    }

    public function testIsConnected()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $isConnected = $baseDao->isConnected();
        $this->assertFalse($isConnected);
        $baseDao->connect();
        $isConnected = $baseDao->isConnected();
        $this->assertTrue($isConnected);
        $baseDao->close();
    }

    public function testPing()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $isPing = $baseDao->ping();
        $this->assertTrue($isPing);
    }

    public function testEscapeString()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $data = $baseDao->escapeString("'");
        $this->assertEquals($data, "\'");
    }

    public function testFetch()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $fetch = $baseDao->call('update test_table set value = value + 1 where id = 1');
        $this->assertTrue(empty($fetch->fetchOne()), 'empty data for update');
        $this->assertEquals($fetch->getAffectedRows(), 1, 'one row update');
        $this->assertTrue($fetch->getStmt(), 'stmt true after update');

        $fetch = $baseDao->call('update test_table set value = 1 where id = 2');
        $this->assertEquals($fetch->getAffectedRows(), 0, 'zero row update');
        $this->assertTrue(empty($fetch->fetchAll()), 'empty data for update');

        $fetch = $baseDao->call('select 1 as id');
        $this->assertTrue($fetch->getStmt() instanceof \mysqli_result);
        $baseDao->close();
    }

    public function testParam()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $data = $baseDao->call('select :number number, :string string', [
            'number' => 1,
            'string' => 'test'
        ])->fetchOne();
        $this->assertEqualsCanonicalizing($data, ['number' => '1', 'string' => 'test']);
        $baseDao->close();
    }

    public function testWrongParamException()
    {
        $this->expectException(DbException::class);
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao->call('call not_exist_proc()');
    }

    public function testDuplicateException()
    {
        $this->expectException(DuplicateRowDbException::class);
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao->call('call test_duplicate_insert()');
    }

    public function testWrongMethodException()
    {
        $this->expectException(DuplicateRowDbException::class);
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);

        $baseDao->call('delete from test_duplicate where id = 2');
        $baseDao->commit();
        $baseDao->insert('test_duplicate', ['id' => [2]]);
        $baseDao->insert('test_duplicate', ['id' => [2]]);
    }

    public function testInsertParam()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);

        $baseDao->call('delete from test_insert_param');
        $baseDao->insert('test_insert_param', [
            'num' => [2],
            'str' => ['test'],
            'json' => [[1, 2, 3]],
            'for_null' => [null],
            'bool' => [true],
            'date' => [new DateTime('2012-11-10 09:08:07')]
        ]);
        $baseDao->commit();
        $data = $baseDao->call('select * from test_insert_param limit 1')->fetchOne();
        $this->assertEqualsCanonicalizing($data, [
            'num' => '2',
            'str' => 'test',
            'json' => '[1, 2, 3]',
            'for_null' => null,
            'bool' => '1',
            'date' => '2012-11-10 09:08:07',
        ]);
    }

    public function testWrongConnection()
    {
        $this->expectException(DbConnectException::class);
        $baseDao = new BaseDao(['master' => 'mysql://root:123@mysqltest:3311/test2']);
        $baseDao->getMysql();
    }

    public function testCustomException()
    {
        $this->expectException(DbException::class);
        $this->expectExceptionMessage('custom-error');
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        $baseDao->call('call test_exception()');
    }

    public function testCustomTextException()
    {
        $baseDao = new BaseDao(['master' => self::MASTER_URL]);
        try {
            $baseDao->call('call test_exception()');
        } catch (DbException $ex) {
            $this->assertEquals($ex->getMessage(), 'custom-error');
            $this->assertEquals($ex->getText(), 'Error execute sql "call test_exception() #  ". Msg "custom-error".');
        }
    }
}