<?php
declare(strict_types=1);

namespace Wumvi\MysqlDao;

class DbDao
{
    protected MysqlFetch $db;

    public function __construct(DbManager $dbManager, bool $isDebug = false)
    {
        $this->db = new MysqlFetch($dbManager, $isDebug);
    }
}
