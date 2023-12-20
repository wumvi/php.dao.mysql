<?php

use Wumvi\Dao\Mysql\BaseDao;

include __DIR__ . '/../vendor/autoload.php';

use Wumvi\DI\Builder;

$di = Builder::makeDi(__DIR__, true, '/config/services.yml');
/** @var BaseDao $baseDao */
$baseDao = $di->get(BaseDao::class);

// $mysql = new \mysqli('192.168.1.96', 'root', '123', 'test', 3311);


//var_dump($baseDao->call('select 1')->fetchOne());

//$url = 'mysql://root:123@192.168.1.96:3311/test';
//$baseDao = new BaseDao([$url]);

//$data = $mysqlFetch->call('select :data as data, :name as name', ['name' => 1, 'data' => 2,]);
//var_dump($data);

//$data = $mysqlFetch->call('select 1 name', [], true);
//var_dump($data);

//$mysqlFetch->call('call add_recall_record(:url, :method, :data)', [
//   'url' => 'ya.ru',
//   'method' => 'GET',
//   'data' => '1233',
//]);

//$data = $baseDao->call('select 1');
//var_dump($data->fetchOne());
// $baseDao->call('call add_recall_record("sfsdf", "POST", "sdfsd")');

$baseDao = new BaseDao([
    'master' => 'mysql://root:123@mysqltest:3311/test',
]);
$baseDao->insert('table_for_insert', [
    'id1' => [1, 2],
    'id2' => ['ff', 'ddd']
]);