<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql\Dao;

use Wumvi\Dao\Mysql\Connection\MasterConnection;
use Wumvi\Dao\Mysql\Connection\ReplicaConnection;

readonly class MasterReplicaDao
{
    public function __construct(
        public MasterConnection $master,
        public ReplicaConnection $replica,
    ) {

    }
}
