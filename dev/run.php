<?php

use Wumvi\Dao\Mysql\BaseDao;

include __DIR__ . '/../vendor/autoload.php';

use Wumvi\DI\Builder;

$di = Builder::makeDi(__DIR__, true, '/config/services.yml');
///** @var BaseDao $baseDao */
//$baseDao = $di->get(BaseDao::class);

$baseDao1 = new BaseDao([
    'master' => 'mysql://root:123@p:mysqltest:3311/test?autocommit=0&flag=32',
]);

//$baseDao2 = new BaseDao([
//    'master' => 'mysql://root:123@mysqltest:3311/test',
//]);

//$baseDao1->call('call create_dead_lock_1()', [], false, MYSQLI_ASYNC);
//var_dump($baseDao1->getThreadId(false));
//
//$baseDao2->call('call create_dead_lock_2()');
//var_dump($baseDao2->getThreadId(false));

//$data = $baseDao1->call('select @@transaction_ISOLATION;')->fetchOne();
//var_dump($data);

//$data = $baseDao1->call('SHOW VARIABLES WHERE Variable_name="autocommit"')->fetchOne();
//var_dump($data);

//$data = $baseDao1->call('SHOW SESSION STATUS LIKE "Ssl_cipher"')->fetchOne();
//var_dump($data);

// $baseDao1->call('call test_duplicate()');

//$baseDao1->callAsync('select :name, :ddd', ['name' => '111', 'ddd' => 433], false);
//$baseDao1->callAsync('select :name, :ddd', ['name' => '111', 'ddd' => 433], false);

//var_dump($baseDao1->call('select :name p, :ddd m', ['name' => '111', 'ddd' => 433], false)->fetchAll());
//var_dump($baseDao1->call('select :name p, :ddd m', ['name' => '111', 'ddd' => 433], false)->fetchAll());

var_dump($baseDao1->call('call test_sleep()', [], false, 0, 'main2')->fetchAll());
var_dump($baseDao1->call('call test_sleep()', [], false, 0, 'main2')->fetchAll());

//var_dump($baseDao1->call('select 1 union select 2', [], false, 0, 'main2')->fetchAll());
//var_dump($baseDao1->call('select 1 union select 2', [], false, 0, 'main2')->fetchAll());


