<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

final readonly class Consts
{
    public const string CONNECTION_IS_EMPTY_MSG = 'connection-is-empty';
    public const string CONNECTION_WRONG_URL_CONNECT = 'wrong-url-connect';
    public const int CONNECTION_IS_EMPTY_CODE = 1;


    public const int DEADLOCK_TRY_COUNT = 2;

    public const int THREAD_ID = -1;
    public const int DEFAULT_PORT = 3306;
}
