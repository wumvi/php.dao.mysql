<?php

use Wumvi\Dao\Mysql\Dao\MasterDao;
use Wumvi\DI\Builder;

include __DIR__ . '/../vendor/autoload.php';

$di = Builder::makeDi(Builder::PREFIX_CLI, __DIR__, true, '/config/services.yml');

/** @var MasterDao $master */
$master = $di->get(MasterDao::class);
$data= $master->master->call('select 1 as id');
var_dump($data->fetchOne());

//$baseDao1 = new Connection([
//    'master' => 'mysql://root:123@mysqltest:3311/test' . '?deadlock-try-count=1',
//]);
//
//$baseDao2 = new Connection([
//    'master' => 'mysql://root:123@mysqltest:3311/test' . '?deadlock-try-count=1',
//]);
