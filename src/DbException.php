<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class DbException extends \Exception
{
    public const string CONNECTION_IS_EMPTY = 'connection is empty';
}