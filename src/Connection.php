<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DbReconnectException;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;
use Wumvi\Dao\Mysql\Exception\UnknownDbException;
use Wumvi\Dao\Mysql\Exception\DbConnectException;

class Connection
{
    private string $dbName;
    private string $user;
    private string $password;
    private string $hostname;
    private readonly int $port;

    private \mysqli|null $mysql = null;

    public int $threadId = Consts::THREAD_ID {
        get {
            return $this->threadId;
        }
    }

    private bool $isAutocommit = false;
    private ?string $unixSocket = null;
    private int $connectionFlags = 0;
    private int $deadLockTryCount = Consts::DEADLOCK_TRY_COUNT {
        get {
            return $this->deadLockTryCount;
        }
    }

    public function __construct(
        private readonly array $db,
        private readonly string $requestId = ''
    ) {
        $count = count($this->db);
        if ($count === 0) {
            throw new DbException(
                'Db connection is empty',
                Consts::CONNECTION_IS_EMPTY_MSG,
                Consts::CONNECTION_IS_EMPTY_CODE
            );
        }
        list($url, $name) = $this->getReadyConnection($this->db);

        $raw = parse_url($url);
        if (!is_array($raw)) {
            throw new DbConnectException('wrong-url-connect');
        }

        if (isset($raw['query'])) {
            parse_str($raw['query'], $query);
            $this->isAutocommit = ($query['autocommit'] ?? '0') === '1';
            $this->unixSocket = $query['socket'] ?? null;
            $this->connectionFlags = isset($query['flag']) ? (int)$query['flag'] : 0;
            $deadlockTryCount = (int)($query['deadlock-try-count'] ?? Consts::DEADLOCK_TRY_COUNT);
            $this->deadLockTryCount = $deadlockTryCount === -1 ? 0 : max($deadlockTryCount, 1);
        }

        $this->dbName = trim($raw['path'] ?? 'main', ' /');
        $this->user = $raw['user'] ?? 'root';
        $this->password = $raw['pass'] ?? 'pwd';
        $this->hostname = $raw['host'] ?? '127.0.0.1';
        $this->port = $raw['port'] ?? Consts::DEFAULT_PORT;

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getMysqlRaw(): \mysqli
    {
        if ($this->mysql === null) {
            $this->mysql = mysqli_init();

            if (!$this->isAutocommit && !$this->mysql->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 0')) {
                throw new DbConnectException('Setting MYSQLI_INIT_COMMAND failed');
            }
            try {
                $this->mysql->real_connect(
                    $this->hostname,
                    $this->user,
                    $this->password,
                    $this->dbName,
                    $this->port,
                    $this->unixSocket,
                    $this->connectionFlags
                );
            } catch (\mysqli_sql_exception $ex) {
                $text = sprintf(
                    Consts::ERROR_CONNECT_MSG,
                    $this->user,
                    $this->hostname,
                    $this->port,
                    $this->mysql->connect_errno,
                    $this->mysql->connect_error
                );
                $this->mysql = null;
                throw new DbConnectException($text, $ex->getMessage(), $ex->getCode(), $ex);
            }
            $this->threadId = $this->mysql->thread_id;
            register_shutdown_function(function () {
                $this->close();
            });
        }

        return $this->mysql;
    }

    /**
     * @return \mysqli
     * @throws \Exception
     */
    public function reconnect(): \mysqli
    {
        $this->close();
        return $this->getMysqlRaw();
    }

    public function close(): void
    {
        try {
            $this->mysql?->close();
        } catch (\Throwable $ex) {

        } finally {
            $this->mysql = null;
        }
    }

    /**
     * Starts a transaction
     * @link https://secure.php.net/manual/en/mysqli.begin-transaction.php
     * @param int $flags [optional]
     * @param string $name [optional]
     *
     * @throws \Exception
     */
    public function beginTransaction(int $flags = 0, string|null $name = null): void
    {
        $status = $this->getMysqlRaw()->begin_transaction($flags, $name);
        if (!$status) {
            throw new DbException('error to start transaction');
        }
    }

    /**
     * @param bool $enable
     *
     * @return void
     *
     * @throws \Exception
     */
    public function autocommit(bool $enable): void
    {
        $status = $this->getMysqlRaw()->autocommit($enable);
        if (!$status) {
            throw new DbException('error to start transaction');
        }
    }

    private function getReadyConnection(array $conn): array
    {
        $count = count($conn);
        $keys = array_keys($conn);
        if ($count === 1) {
            return [$conn[$keys[0]], $keys[0]];
        }

        $key = $keys[mt_rand(0, $count - 1)];

        return [$conn[$key], $key];
    }

    public function isConnected(): bool
    {
        return $this->threadId !== Consts::THREAD_ID;
    }

    public function insert1D(string $table, array $columns, array $planeArray): void
    {

    }

    public function insertSingle() {

    }

    public function insert2D() {

    }

    public function insert2DTransaction() {

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


    /**
     * @param int $flags
     * @return void
     * @throws \Exception
     */
    public function commit(int $flags = 0): void
    {
        try {
            $this->getMysqlRaw()->commit($flags);
        } catch (\mysqli_sql_exception $ex) {
            throw new DbException('error to commit', $ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    public function query(
        callable $cb,
        string $sql,
    ) {
        $mysqlRaw = $this->getMysqlRaw();
        $result = false;
        $deadlockEx = null;
        $tryCount = 1;
        $isDeadlock = false;
        for ($tryIndex = 0; $tryIndex < $tryCount; $tryIndex++) {
            try {
                $result = $cb($mysqlRaw);
            } catch (\mysqli_sql_exception $ex) {
                $text = sprintf(Consts::ERROR_MSG, $sql, $ex->getMessage());
                if ($ex->getCode() === 1062) {
                    throw new DuplicateRowDbException($text, Consts::DUPLICATE_MSG, $ex->getCode(), $ex);
                }

                if ($ex->getCode() === 1213) {
                    $deadlockEx = $ex;
                    if (!$isDeadlock) {
                        $tryCount += $this->deadLockTryCount;
                        $isDeadlock = true;
                    }
                    continue;
                }

                if (($ex->getCode() === 2006 || $ex->getCode() === 2013)) {
                    throw new DbReconnectException('reconnect');
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
        $mysqlRaw = $this->getMysqlRaw();

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
        $isDeadlock = false;
        for ($tryIndex = 0; $tryIndex < $tryCount; $tryIndex++) {
            try {
                if (empty($params)) {
                    $result = $mysqlRaw->query($sql, $mode);
                } else {
                    $stmt = $mysqlRaw->prepare($sql);
                    if ($stmt === false) {
                        throw new DbException('prepare is wrong: ' . $sql);
                    }

                    $stmt->bind_param($types, ...$binds);
                    $result = $stmt->execute();
                    if ($result === false) {
                        throw new DbException("Prepare failed: (" . $mysqlRaw->errno . ") " . $mysqlRaw->error);
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
                        $tryCount += $this->deadLockTryCount;
                        $isDeadlock = true;
                    }
                    continue;
                }

                if (($ex->getCode() === 2006 || $ex->getCode() === 2013)) {
                    throw new DbReconnectException('reconnect');
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

        return new Fetch($result, $mysqlRaw);
    }

    public function escapeString(string $data): string
    {
        return $this->getMysqlRaw()->real_escape_string($data);
    }
}
