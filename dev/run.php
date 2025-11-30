<?php

use Wumvi\Dao\Mysql\Dao\MasterDao;
use \Wumvi\Dao\Mysql\Connection;
use Wumvi\DI\Builder;

include __DIR__ . '/../vendor/autoload.php';

$di = Builder::makeDi(Builder::PREFIX_CLI, __DIR__, true, '/config/services.yml');

/** @var MasterDao $master */
$master = $di->get(MasterDao::class);
//$data= $master->master->call('select 1 as id');
//var_dump($data->fetchOne());

$conn1 = new Connection(['m' => 'mysql://root:pwd@127.0.0.1:3432/test']);
$mysqli = $conn1->getMysqlRaw();
//$conn->call('select 1');
//$conn->call('select 1');


//$arr = [];
//$arr = array_merge($arr, [1,2,3]);
//$arr = array_merge($arr, [1,2,3]);
//var_dump($arr);

// $mysqli->multi_query("SELECT 1 union select 2; SELECT 2;");


//$stmt = $mysqli->prepare("SELECT 1");
//$stmt->execute();
// $stmt->free_result();
//
//$mysqli->query("SELECT 2");

//var_dump($mysqli->query('select @@autocommit as autocommit')->fetch_assoc());
//
// $conn1->insertSingle('data', ['value'=>1]);
// $conn1->insert1DBigBind('data', ['value'], [9, 3]);
// $conn1->insert2DMultiBind('data', ['value'], [[44], [33]]);

//$conn1->select('test_table', ['value'], ['id' => 1]);
//$conn1->delete('test_table', ['id' => 1]);
//$conn1->update('test_table', ['value' => 2], ['id' => 1]);

$conn = new Connection([
    'r1' => 'mysql://root:pwd@127.0.0.1:3433/test',
    'r2' => 'mysql://root:pwd@127.0.0.1:3434/test'
], 'req-id');

