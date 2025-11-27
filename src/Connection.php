<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;
use Wumvi\Dao\Mysql\Exception\UnknownDbException;
use Wumvi\Dao\Mysql\Exception\DbConnectException;

class Connection
{
    private ?Mysql $mysql = null;

    public function __construct(
        private readonly array $db,
        private readonly string $requestId = ''
    ) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    public function close(): void
    {
        $this->mysql?->close();
    }

    private function getReadyConnection(array $conn): array
    {
        $count = count($conn);
        $keys = array_keys($conn);
//        if ($count === 1) {
//            return [$conn[0], $keys[0]];
//        }

        $key = $keys[mt_rand(0, $count - 1)];

        return [$conn[$key], $key];
    }

    /**
     * @return Mysql
     *
     * @throws DbException
     */
    private function getConnection(): Mysql
    {
        if ($this->mysql !== null) {
            return $this->mysql;
        }

        $count = count($this->db);
        if ($count === 0) {
            throw new DbException(
                'Db connection is empty',
                Consts::CONNECTION_IS_EMPTY_MSG,
                Consts::CONNECTION_IS_EMPTY_CODE
            );
        }
        list($url, $name) = $this->getReadyConnection($this->db);
        $this->mysql = new Mysql($url, $name);

        return $this->mysql;
    }

    /**
     * @return \mysqli
     * @throws DbException
     * @throws DbConnectException
     */
    public function getMysqlRawHandle(): \mysqli
    {
        $con = $this->getConnection();
        return $con->getMysqlConnection();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return int
     * @throws DbException
     */
    public function getThreadId(): int
    {
        $con = $this->getConnection();
        return $con->getThreadId();
    }

    public function isConnected(): bool
    {
        return $this->getConnection()->getThreadId() !== Consts::THREAD_ID;
    }

//    /**
//     * @param string $table
//     * @param array $data
//     * @param string $function
//     * @return int
//     * @throws DbConnectException
//     * @throws DbException
//     * @throws DuplicateRowDbException
//     * @throws UnknownDbException
//     */
//    public function insert(string $table, array $data, string $function = ''): int
//    {
//        $mysql = $this->getMysqlRawHandle();
//
//        $keys = array_keys($data);
//        $fields = '`' . implode('`,`', $keys) . '`';
//        $sql = 'INSERT INTO ' . $table . ' (' . $fields . ') values ';
//        $count = count($data[$keys[0]]);
//        for ($i = 0; $i < $count; $i++) {
//            $values = [];
//            foreach ($keys as $field) {
//                $values[] = Utils::convert($data[$field][$i], $mysql);
//            }
//            $sql .= ' (' . implode(',', $values) . '),';
//        }
//        $sql = substr($sql, 0, strlen($sql) - 1);
//        $sql .= ' # ' . $function . ' ' . $this->requestId;
//        try {
//            $mysql->query($sql);
//            return $mysql->insert_id;
//        } catch (\mysqli_sql_exception $ex) {
//            $text = sprintf(Consts::ERROR_MSG, $sql, $ex->getMessage());
//            if ($ex->getCode() === 1062) {
//                throw new DuplicateRowDbException($text, Consts::DUPLICATE_MSG, $ex->getCode(), $ex);
//            }
//
//            throw new DbException($text, $ex->getMessage(), $ex->getCode(), $ex);
//        } catch (\Throwable $ex) {
//            throw new UnknownDbException($ex->getMessage(), Consts::UNKNOWN_MSG, $ex->getCode(), $ex);
//        }
//    }

    public function connect(): bool
    {
        return $this->getConnection()->getMysqlConnection() !== null;
    }

    public function commit()
    {
        $mysql = $this->getConnection()->getMysqlConnection();
        try {
            $mysql->query('commit');
        } catch (\mysqli_sql_exception $ex) {
            throw new DbException($text, $ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    /**
     * @throws DbException
     */
    public function call(
        string $sql,
        array $params = [],
        string $function = '',
        int $mode = MYSQLI_STORE_RESULT
    ): Fetch|null {
        $conn = $this->getConnection();
        $mysql = $conn->getMysqlConnection();

        if (!empty($params)) {
            $order = [];
            $sql = preg_replace_callback('/:p_[\w_]+/', function ($matches) use (&$order) {
                $order[] = substr($matches[0], 1);
                return '?';
            }, $sql);

            $types = '';
            $binds = [];
            foreach ($order as $name) {
                $param = $params[$name];
                list($value, $type) = is_array($param) ? $param : [$param, 's'];
                $types .= $type;
                $binds[] = $value;
            }
        }

        $sql .= ' # ' . $function . ' ' . ($this->requestId ?? '');
        $result = false;
        $deadlockEx = null;
        $tryCount = 1;
        $isReconnect = false;
        $isDeadlock = false;
        for ($tryIndex = 0; $tryIndex < $tryCount; $tryIndex++) {
            try {
                if (empty($params)) {
                    $result = $mysql->query($sql, $mode);
                } else {
                    $stmt = $mysql->prepare($sql);
                    if ($stmt === false) {
                        throw new DbException('prepare is wrong: ' . $sql);
                    }

                    $stmt->bind_param($types, ...$binds);
                    $result = $stmt->execute();
                    if ($result === false) {
                        throw new DbException("Prepare failed: (" . $mysql->errno . ") " . $mysql->error);
                    }
                    $result = $stmt->get_result();
                }
            } catch (\mysqli_sql_exception $ex) {
                $text = sprintf(Consts::ERROR_MSG, $sql, $ex->getMessage());
                if ($ex->getCode() === 1062) {
                    throw new DuplicateRowDbException($text, Consts::DUPLICATE_MSG, $ex->getCode(), $ex);
                }

                if ($ex->getCode() === 1213) {
                    $deadlockEx = $ex;
                    if (!$isDeadlock) {
                        $tryCount += $conn->getDeadLockTryCount();
                        $isDeadlock = true;
                    }
                    continue;
                }

                if (!$isReconnect && ($ex->getCode() === 2006 || $ex->getCode() === 2013)) {
                    $this->mysql->reconnect();
                    $tryCount++;
                    $isReconnect = true;
                    continue;
                }

                throw new DbException($text, $ex->getMessage(), $ex->getCode(), $ex);
            } catch (\Throwable $ex) {
                throw new UnknownDbException($ex->getMessage(), Consts::UNKNOWN_MSG, $ex->getCode(), $ex);
            }

            $deadlockEx = null;
            break;
        }

        if ($deadlockEx !== null) {
            $text = sprintf(Consts::ERROR_MSG, $sql, $deadlockEx->getMessage());
            throw new DeadlockException($text, $deadlockEx->getMessage(), $deadlockEx->getCode(), $deadlockEx);
        }

        if ($result === false) {
            return null;
        }

        return new Fetch($result, $mysql);
    }

    public function escapeString(string $data): string
    {
        return $this->getMysqlRawHandle()->real_escape_string($data);
    }
}
