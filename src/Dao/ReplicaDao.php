<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql\Dao;

use Wumvi\Dao\Mysql\Connection\ReplicaConnection;

readonly class ReplicaDao
{
    public function __construct(
        public ReplicaConnection $replica
    ) {

    }
}
