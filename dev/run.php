<?php

use Wumvi\MysqlDao\DbManager;
use Wumvi\MysqlDao\MysqlFetch;

include __DIR__ . '/../vendor/autoload.php';

$url = 'mysql://service:service@localhost:3316/recall_request';
$mysqlFetch = new MysqlFetch(new DbManager($url, false), true);

//$data = $mysqlFetch->call('select :data as data, :name as name', ['name' => 1, 'data' => 2,]);
//var_dump($data);

//$data = $mysqlFetch->call('select 1 name', [], true);
//var_dump($data);

//$mysqlFetch->call('call add_recall_record(:url, :method, :data)', [
//   'url' => 'ya.ru',
//   'method' => 'GET',
//   'data' => '1233',
//]);

$mysqlFetch->call('call add_recall_record("sfsdf", "POST", "sdfsd")');