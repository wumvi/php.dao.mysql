<?php
declare(strict_types=1);

namespace Wumvi\MysqlDao;

class MysqlFetch
{
    private DbManager $dbManager;
    private bool $isDebug;
    private int $affectedRows = 0;

    public function __construct(DbManager $dbManager, $isDebug = false)
    {
        $this->dbManager = $dbManager;
        $this->isDebug = $isDebug;
    }

    /**
     * Возвращает количество затронутых записей
     *
     * @return int
     */
    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    /**
     * Возвращает последний вставленный ключ
     *
     * @return int
     *
     * @throws DbException
     */
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

    private function fetchSimpleQuery(\mysqli $mysql, string $sql, bool $fetchFirst): array
    {
        $result = $mysql->query($sql);
        if ($mysql->error) {
            $error = $mysql->error . ' ' . $mysql->errno;
            self::triggerError($error, $sql, [], $this->isDebug);
            throw new DbException('error-to-prepare-' . $sql);
        }

        if (is_bool($result)) {
            return [];
        }

        $data = $fetchFirst ? $result->fetch_assoc() : $result->fetch_all(MYSQLI_ASSOC);
        $this->closeResult($mysql);

        return $data;
    }

    private function fetchPrepareQuery(\mysqli $mysql, string $sql, array $orderVars, bool $fetchFirst)
    {
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

        $this->closeResult($mysql);

        return $result;
    }

    public function call(string $sql, array $vars = [], bool $fetchFirst = false): array|false|null
    {
        $orderVars = [];
        $sql = preg_replace_callback('/:(?<name>[\w_]+)/', function ($matches) use (&$orderVars, $vars) {
            $varName = $matches['name'];
            $orderVars[] = $vars[$varName];
            return '?';
        }, $sql);

        $mysql = $this->dbManager->getConnection();

        if (empty($orderVars)) {
            return $this->fetchSimpleQuery($mysql, $sql, $fetchFirst);
        }

        return $this->fetchPrepareQuery($mysql, $sql, $orderVars, $fetchFirst);
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

    private function closeResult($mysql)
    {
        if ($mysql->more_results()) {
            do {
                $mysql->next_result();
                $mysql->more_results();
            } while ($mysql->more_results());
        }
    }
}
