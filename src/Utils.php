<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Utils
{
    public static function convert($value, \mysqli $mysql): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return '\'' . $mysql->escape_string($value) . '\'';
        }


        return $value . '';
    }
}