<?php
declare(strict_types=1);

use Wumvi\Dao\Mysql\BaseDao;

include __DIR__ . '/../vendor/autoload.php';

$baseDao1 = new BaseDao([
    'master' => 'mysql://root:123@mysqltest:3311/test',
]);
$baseDao1->call('call create_dead_lock_1()', [], false);
echo 'end', PHP_EOL;
