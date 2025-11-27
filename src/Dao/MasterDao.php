<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql\Dao;

use Wumvi\Dao\Mysql\Connection\MasterConnection;

readonly class MasterDao
{
    public function __construct(
        public MasterConnection $master
    ) {

    }
}
