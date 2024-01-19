<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;
use Wumvi\Dao\Mysql\Exception\UnknownDbException;
use Wumvi\Dao\Mysql\Exception\DbConnectException;

class BaseDao
{
    private ?Connection $master = null;
    private ?Connection $slave = null;

    public function __construct(
        private readonly array $masters = [],
        private readonly array $slaves = [],
        private readonly string $requestId = ''
    ) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    public function close(): void
    {
        $this->master?->close();
        $this->slave?->close();
    }

    private function getReadyConnection(array $conn): array
    {
        $count = count($conn);
        $keys = array_keys($conn);
        $key = $keys[mt_rand(0, $count - 1)];

        return [$key, $conn[$key]];
    }

    /**
     * @param bool $isSlave
     * @return Connection
     *
     * @throws DbException
     */
    private function getConnection(bool $isSlave = Consts::DEFAULT_SLAVE): Connection
    {
        if ($isSlave && $this->slave !== null) {
            return $this->slave;
        }

        if ($this->master !== null) {
            return $this->master;
        }

        $count = count($this->slaves);
        if ($isSlave && $count > 0) {
            list($name, $url) = $this->getReadyConnection($this->slaves);
            $this->slave = new Connection($name, $url);
            return $this->slave;
        }

        $count = count($this->masters);
        if ($count === 0) {
            throw new DbException(
                'Master or slave connection is empty',
                Consts::CONNECTION_IS_EMPTY_MSG,
                Consts::CONNECTION_IS_EMPTY_CODE
            );
        }
        list($name, $url) = $this->getReadyConnection($this->masters);
        $this->master = new Connection($name, $url);
        return $this->master;
    }

    /**
     * @param bool $isSlave
     * @return \mysqli
     * @throws DbException
     * @throws DbConnectException
     */
    public function getMysql(bool $isSlave = Consts::DEFAULT_SLAVE): \mysqli
    {
        $con = $this->getConnection($isSlave);
        return $con->getMysqlConnection();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param bool $isSlave
     * @return int
     * @throws DbException
     */
    public function getThreadId(bool $isSlave = Consts::DEFAULT_SLAVE): int
    {
        $con = $this->getConnection($isSlave);
        return $con->getThreadId();
    }

    public function isConnected(bool $isSlave = Consts::DEFAULT_SLAVE): bool
    {
        $con = $this->getConnection($isSlave);
        return $con->getThreadId() !== Consts::THREAD_ID;
    }

    /**
     * @param string $table
     * @param array $data
     * @param string $function
     * @return int
     * @throws DbConnectException
     * @throws DbException
     * @throws DuplicateRowDbException
     * @throws UnknownDbException
     */
    public function insert(string $table, array $data, string $function = ''): int
    {
        $mysql = $this->getMysql(false);

        $keys = array_keys($data);
        $fields = '`' . implode('`,`', $keys) . '`';
        $sql = 'INSERT INTO ' . $table . ' (' . $fields . ') values ';
        $count = count($data[$keys[0]]);
        for ($i = 0; $i < $count; $i++) {
            $values = [];
            foreach ($keys as $field) {
                $values[] = Utils::convert($data[$field][$i], $mysql);
            }
            $sql .= ' (' . implode(',', $values) . '),';
        }
        $sql = substr($sql, 0, strlen($sql) - 1);
        $sql .= ' # ' . $function . ' ' . $this->requestId;
        try {
            $mysql->query($sql);
            return $mysql->insert_id;
        } catch (\mysqli_sql_exception $ex) {
            $text = sprintf(Consts::ERROR_MSG, $sql, $ex->getMessage());
            if ($ex->getCode() === 1062) {
                throw new DuplicateRowDbException($text, Consts::DUPLICATE_MSG, $ex->getCode(), $ex);
            }

            throw new DbException($text, $ex->getMessage(), $ex->getCode(), $ex);
        } catch (\Throwable $ex) {
            throw new UnknownDbException($ex->getMessage(), Consts::UNKNOWN_MSG, $ex->getCode(), $ex);
        }
    }

    public function connect(bool $isSlave = Consts::DEFAULT_SLAVE): bool
    {
        $conn = $this->getConnection($isSlave);
        return $conn->getMysqlConnection() !== null;
    }

    public function commit(bool $isSlave = Consts::DEFAULT_SLAVE)
    {
        $conn = $this->getConnection($isSlave);
        $mysql = $conn->getMysqlConnection();
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
        bool $isSlave = Consts::DEFAULT_SLAVE,
        string $function = '',
        int $mode = MYSQLI_STORE_RESULT
    ): Fetch {
        $conn = $this->getConnection($isSlave);
        $mysql = $conn->getMysqlConnection();

        if (!empty($params)) {
            $order = [];
            $sql = preg_replace_callback('/:[\w_]+/', function ($matches) use (&$order) {
                $order[] = substr($matches[0], 1);
                return '?';
            }, $sql);
            $requestId = $this->requestId ? ' # ' . $this->requestId : '';
            $sql .= $requestId;

            $types = '';
            $binds = [];
            foreach ($order as $name) {
                $param = $params[$name];
                list($value, $type) = is_array($param) ? $param : [$param, 's'];
                $types .= $type;
                $binds[] = $value;
            }
        }

        $sql .= ' # ' . $function . ' ' . $this->requestId;
        $result = false;
        $deadlockEx = null;
        $deadlockTryCount = $conn->getDeadLockTryCount();
        for ($deadlockIndex = 0; $deadlockIndex < $deadlockTryCount; $deadlockIndex++) {
            try {
                if (empty($params)) {
                    $result = $mysql->query($sql, $mode);
                } else {
                    $stmt = $mysql->prepare($sql);
                    $stmt->bind_param($types, ...$binds);
                    $stmt->execute();
                    $result = $stmt->get_result();
                }
            } catch (\mysqli_sql_exception $ex) {
                $text = sprintf(Consts::ERROR_MSG, $sql, $ex->getMessage());
                if ($ex->getCode() === 1062) {
                    throw new DuplicateRowDbException($text, Consts::DUPLICATE_MSG, $ex->getCode(), $ex);
                }

                if ($ex->getCode() === 1213) {
                    $deadlockEx = $ex;
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
            throw new UnknownDbException('result is false', Consts::UNKNOWN_MSG);
        }

        return new Fetch($result, $mysql);
    }

    /**
     * @param bool $isSlave
     * @return bool
     * @throws DbConnectException
     * @throws DbException
     */
    public function ping(bool $isSlave = Consts::DEFAULT_SLAVE): bool
    {
        return $this->getMysql($isSlave)->ping();
    }

    public function escapeString(string $data, bool $isSlave = Consts::DEFAULT_SLAVE): string
    {
        return $this->getMysql($isSlave)->real_escape_string($data);
    }
}
