<?php
declare(strict_types=1);

namespace Wumvi\MysqlDao;

class MysqlFetch
{
    public const DEFAULT_SELECT_MOD = '*';
    public const UNLIMIT = -1;

    private DbManager $dbManager;
    private bool $isDebug;
    private int $affectedRows = 0;

    public function __construct(DbManager $dbManager, $isDebug = false)
    {
        $this->dbManager = $dbManager;
        $this->isDebug = $isDebug;
    }

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    public function getLastInsertId(): int
    {
        return $this->dbManager->getConnection()->insert_id;
    }

    private function makeTypes($var): string
    {
        switch (gettype($var)) {
            case 'boolean':
            case 'integer':
                return 'i';
            case 'double':
                return 'd';
            case 'string':
                return 's';
            default:
                throw new DbException('Unsupported type for ' . $var);
        }
    }

    public function call(string $sql, array $vars = [], bool $fetchFirst = false): array
    {
        $orderVars = [];
        $sql = preg_replace_callback('/:(?<name>[\w_]+)/', function ($matches) use (&$orderVars, $vars) {
            $varName = $matches['name'];
            $orderVars[] = $vars[$varName];
            return '?';
        }, $sql);

        $mysql = $this->dbManager->getConnection();

        if (empty($orderVars)) {
            $result = $mysql->query($sql);
            if ($mysql->error) {
                $error = $mysql->error . ' ' . $mysql->errno;
                self::triggerError($error, $sql, $orderVars, $this->isDebug);
                throw new DbException('error-to-prepare-' . $sql);
            }

            if (is_bool($result)) {
                return [];
            }

            return $fetchFirst ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
        }

        $types = '';
        foreach ($orderVars as $var) {
            $types .= $this->makeTypes($var);
        }

        $stmt = $mysql->prepare($sql);
        if ($stmt === false) {
            $error = $mysql->error . ' ' . $mysql->errno;
            self::triggerError($error, $sql, $orderVars, $this->isDebug);
            throw new DbException('error-to-prepare-' . $sql);
        }

        $status = $stmt->bind_param($types, ...$orderVars);
        if ($status === false) {
            self::triggerError($stmt->sqlstate, $sql, $orderVars, $this->isDebug);
            throw new DbException('error-to-bind-param-' . $sql);
        }

        $status = $stmt->execute();
        if ($status === false) {
            self::triggerError($stmt->sqlstate, $sql, $orderVars, $this->isDebug);
            throw new DbException('error-to-exec-' . $sql);
        }

        $this->affectedRows = $stmt->affected_rows;

        $result = $stmt->get_result();
        if ($result === false) {
            return [];
        }

        $result = $fetchFirst ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result;
    }

    /**
     * @param string $error
     * @param string $sql
     * @param array<mixed> $vars
     * @param bool $isDebug
     */
    public static function triggerError(string $error, string $sql, array $vars, bool $isDebug)
    {
        if ($isDebug) {
            $msg = sprintf(
                "Msg: %s\nSql: %s\nVars: %s",
                $error,
                $sql,
                var_export($vars, true)
            );
            trigger_error($msg);
        }
    }
}
