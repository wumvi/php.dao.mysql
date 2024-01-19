<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Consts
{
    public const string CONNECTION_IS_EMPTY_MSG = 'connection-is-empty';
    public const int CONNECTION_IS_EMPTY_CODE = 1;

    public const string UNKNOWN_MSG = 'unknown';
    public const string DUPLICATE_MSG = 'duplicate';

    public const string ERROR_MSG = 'Error execute sql "%s". Msg "%s".';
    public const string ERROR_CONNECT_MSG = 'Error to connect to mysql server %s@%s:%s:%s:%s';

    public const int DEADLOCK_TRY_COUNT = 2;

    public const int THREAD_ID = -1;
    public const int DEFAULT_PORT = 3306;

    public const bool DEFAULT_SLAVE = false;
}
