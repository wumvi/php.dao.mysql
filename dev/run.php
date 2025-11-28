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

$conn1 = new Connection(['m1' => 'mysql://root:pwd@127.0.0.1:3432/test']);
/// $conn2 = new Connection(['m2' => 'mysql://root:pwd@127.0.0.1:3432/test' . '?deadlock-try-count=-1']);

//
$mysqli = $conn1->getMysqlRaw();

//var_dump($mysqli->query('select @@autocommit as autocommit')->fetch_assoc());

//$stmt = $mysqli->query("insert into table_for_insert (id1,id2) values(1,'dd')");

// $conn1->insertSingle('data', ['value'], [1]);
// $conn1->insert2D('data', ['value'], [[9], [3]]);
// $conn1->insert2DTransaction('data', ['value'], [[9], [3]]);

// $conn1->insert1D('data', ['value'], [1, 2, 3]);

$conn1->insert1D('table_for_insert', ['id1', 'id2'], [1, 2, 3, 4]);

//$stmt = $mysqli->prepare('insert into table_for_insert (id1,id2) values(?, ?)');
//
//$arg = [null, null];
//$param = [];
//foreach($arg as &$a) {
//    $param[] = $a;
//}
//
//
//$stmt->bind_param('is', ...$param);
//
//foreach([1,2] as $key => &$a) {
//    $param[$key] = $a;
//}
//
//$stmt->execute();
//
//foreach([3, 4] as $key => &$a) {
//    $param[$key] = $a;
//}
//$stmt->execute();

//$a = 1;
//$b = '2';
// $stmt->bind_param('is', $a, $b);
// $stmt->execute();


//
//$conn1->autocommit(false);
//$conn2->autocommit(false);
//
//$conn1->beginTransaction();
//$conn2->beginTransaction();
//
//try {
//    $conn1->call('update test_deadlock_table set val = val + 1 where id = 4', [], 'conn1');
//    $conn2->call('update test_deadlock_table set val = val + 1 where id = 3', [], 'conn2');
//
//    $conn1->call('update test_deadlock_table set val = val + 2 where id = 3', [], '', MYSQLI_ASYNC);
//    usleep(200);
//    $conn2->call('update test_deadlock_table set val = val + 2 where id = 4');
//} catch (\Exception $ex) {
//
//}
//
//$conn2->rollback();
//$conn1->rollback();
//
//$conn1->close();
//$conn2->close();