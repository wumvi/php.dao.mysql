<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Utils
{
    public static function convert($value, \mysqli $mysql): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_string($value)) {
            return '\'' . $mysql->escape_string($value) . '\'';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTime) {
            return '\'' . $value->format('Y-m-d H:i:s') . '\'';
        }

        if (is_array($value)) {
            return '\'' . json_encode($value) . '\'';
        }

        return $value . '';
    }
}

