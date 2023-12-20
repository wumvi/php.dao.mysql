<?php

use PHPUnit\Framework\TestCase;
use Wumvi\Dao\Mysql\BaseDao;
use Wumvi\Dao\Mysql\DbException;

class DaoCommonTest extends TestCase
{
    public function testMasterConstructor(): void
    {
        $this->expectNotToPerformAssertions();
        $url = 'mysql://root:123@mysqltest:3311/test';
        $baseDao = new BaseDao(['master' => $url]);
        $baseDao->close();
    }

    public function testMasterSlaveExec(): void
    {
        $baseDao = new BaseDao([
            'master' => 'mysql://root:123@mysqltest:3311/test',
        ], [
            'slave1' => 'mysql://replica1:123@mysqltest:3311/test'
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
            'slave1' => 'mysql://replica1:123@mysqltest:3311/test',
            'slave2' => 'mysql://replica1:123@mysqltest:3311/test',
        ]);
        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'replica1@%');
        $data = $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $this->assertEquals($data['user'], 'replica1@%');
        $baseDao->close();
    }

    public function testConnectionNotFound(): void
    {
        $this->expectExceptionMessage(DbException::CONNECTION_IS_EMPTY);
        $baseDao = new BaseDao([], []);
        $baseDao->call('select CURRENT_USER() as user', [], true, 'run')->fetchOne();
        $baseDao->close();
    }

    public function testGetRawMysql(): void
    {
        $baseDao = new BaseDao(['master' => 'mysql://root:123@mysqltest:3311/test',]);
        $mysql = $baseDao->getMysql(true);
        $this->assertTrue($mysql instanceof \mysqli, 'is mysql');
        $baseDao->close();


        $baseDao = new BaseDao([
            'master' => 'mysql://root:123@mysqltest:3311/test',
        ], [
            'slave1' => 'mysql://replica1:123@mysqltest:3311/test'
        ]);
        $data = $baseDao->call('select :name as name', ['name' => [34, 's']], false, 'run')->fetchOne();
        $baseDao->close();
    }

    public function testInsert(): void
    {
        $baseDao = new BaseDao([
            'master' => 'mysql://root:123@mysqltest:3311/test',
        ]);
        $baseDao->call('truncate table table_for_insert');
        $baseDao->insert('table_for_insert', [
            'id1' => [1, 2],
            'id2' => ['ff', 'ddd']
        ]);

        $data = $baseDao->call('select * from table_for_insert')->fetchOne();
        $this->assertIsArray($data, 'is array');
        $this->assertArrayHasKey('id1', $data);
        $this->assertArrayHasKey('id2', $data);
        $this->assertTrue($data['id1'] === '1' && $data['id2'] === 'ff');
    }

    public function testInsertException(): void
    {
        $this->expectException(DbException::class);
        $baseDao = new BaseDao([
            'master' => 'mysql://root:123@mysqltest:3311/test',
        ]);
        $baseDao->insert('table_for_not_found', [
            'id1' => [1, 2],
            'id2' => ['ff', 'ddd']
        ]);
    }
}