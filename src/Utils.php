<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Utils
{
    public static function convertDateToString(\DateTime $data)
    {
        return $data->format('Y-m-d H:i:s');
    }
}

