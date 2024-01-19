<?php

use Wumvi\Dao\Mysql\BaseDao;

include __DIR__ . '/../vendor/autoload.php';

use Wumvi\DI\Builder;

$di = Builder::makeDi(__DIR__, true, '/config/services.yml');
///** @var BaseDao $baseDao */
//$baseDao = $di->get(BaseDao::class);

$baseDao1 = new BaseDao([
    'master' => 'mysql://root:123@mysqltest:3311/test' . '?deadlock-try-count=1',
]);

$baseDao2 = new BaseDao([
    'master' => 'mysql://root:123@mysqltest:3311/test' . '?deadlock-try-count=1',
]);
