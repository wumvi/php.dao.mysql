<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbEmptyDataException;
use Wumvi\Dao\Mysql\Exception\DbException;
use Wumvi\Dao\Mysql\Exception\DbReconnectException;
use Wumvi\Dao\Mysql\Exception\DeadlockException;
use Wumvi\Dao\Mysql\Exception\DuplicateRowDbException;
use Wumvi\Dao\Mysql\Exception\DbUnknownException;
use Wumvi\Dao\Mysql\Exception\DbConnectException;

class Connection
{
    public const int D1D = 1;
    public const int D2D = 2;

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

    private bool $isAutocommit = true;
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
            $this->isAutocommit = ($query['autocommit'] ?? '1') === '1';
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
            $result = $this->mysql->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = ' . intval($this->isAutocommit));
            if (!$result) {
                throw new DbConnectException('db init error', 'Setting MYSQLI_INIT_COMMAND failed');
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
                    'Error to connect to mysql server %s@%s:%s:%s:%s %s',
                    $this->user,
                    $this->hostname,
                    $this->port,
                    $this->mysql->connect_errno,
                    $this->mysql->connect_error,
                    $ex->getMessage()
                );
                $this->mysql = null;
                throw new DbConnectException('connect error', $text, $ex->getCode(), $ex);
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
            throw new DbException('begin transaction error', 'error to start transaction');
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
            throw new DbException('autocommit error', 'error to set autocommit: ' . ($enable ? 'true' : 'false'));
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

    private function phpTypeToMysqli($value): string
    {
        return match (true) {
            is_int($value) => 'i',
            is_float($value) => 'd',
            is_bool($value) => 'i',
            is_null($value) => 's',
            default => 's',
        };
    }

    function buildMysqliParamTypes(array $values): string
    {
        $types = '';
        foreach ($values as $v) {
            $types .= $this->phpTypeToMysqli($v);
        }
        return $types;
    }

    private function getColumns(array|string $columns = []): string
    {
        if (empty($columns)) {
            return '*';
        }
        return is_string($columns) ? $columns : ('`' . implode('`,`', $columns) . '`');
    }

    private function getCondition(array $where): string
    {
        if (empty($where)) {
            return '';
        }

        $condition = [];
        foreach ($where as $key => $value) {
            $condition[] = '`' . $key . '`=?';
        }

        return ' where ' . implode(',', $condition);
    }

    private function prepExec($sql, array $where): \mysqli_stmt
    {
        return $this->query(function (\mysqli $mysqli) use ($sql, $where) {
            $stmt = $this->prepare($sql, $mysqli);
            if (!empty($where)) {
                $types = $this->buildMysqliParamTypes($where);
                $values = array_values($where);
                $this->bind($stmt, $sql, $mysqli, $types, $values);
            }
            $this->execute($stmt, $sql, $mysqli);
            return $stmt;
        }, $sql);
    }

    public function select(
        bool $isOne,
        string $table,
        array|string $columns = [],
        array $where = [],
        string $function = ''
    ): array|null {
        $columns = $this->getColumns($columns);
        $sql = "select $columns from $table";
        $sql .= $this->getCondition($where);
        $sql .= $this->getComment($function);
        $stmt = $this->prepExec($sql, $where);

        $result = $stmt->get_result();
        if (is_bool($result)) {
            $stmt->free_result();
            return [];
        }

        $mysqli = $this->getMysqlRaw();
        $fetch = new Fetch($result, $mysqli);

        return $isOne ? $fetch->fetchOne() : $fetch->fetchAll();
    }

    public function update(
        string $table,
        array $set = [],
        array $where = [],
        string $function = ''
    ): int {
        $columns = [];
        foreach ($set as $key => $value) {
            $columns[] = '`' . $key . '`=?';
        }
        $columns = implode(',', $columns);

        $sql = 'update ' . $table . ' set ' . $columns;
        $sql .= $this->getCondition($where);
        $sql .= $this->getComment($function);

        $this->query(function (\mysqli $mysqli) use ($sql, $set, $where) {
            $stmt = $this->prepare($sql, $mysqli);
            $bindSet = array_values($set);
            if (!empty($where)) {
                $types = $this->buildMysqliParamTypes($set);
                $types .= $this->buildMysqliParamTypes($where);
                $bindWhere = array_values($where);
                $result = $stmt->bind_param($types, ...$bindSet, ...$bindWhere);
                if ($result === false) {
                    throw new DbException(
                        'bind is failed',
                        ' bind is failed: ' . $sql . ' ' . $mysqli->error . ' ' . $mysqli->errno,
                        $mysqli->errno
                    );
                }
            } else {
                $types = $this->buildMysqliParamTypes($set);
                $this->bind($stmt, $sql, $mysqli, $types, $bindSet);
            }
            $this->execute($stmt, $sql, $mysqli);
            return $stmt;
        }, $sql);

        $mysqli = $this->getMysqlRaw();
        return $mysqli->affected_rows;
    }

    public function delete(
        string $table,
        array $where = [],
        string $function = ''
    ): int {
        $sql = 'delete from ' . $table;
        $sql .= $this->getCondition($where);
        $sql .= $this->getComment($function);

        $this->prepExec($sql, $where);
        $mysqli = $this->getMysqlRaw();

        return $mysqli->affected_rows;
    }

    public function insertSingle(
        string $table,
        array|string $columns = [],
        array $values = [],
        string $function = ''
    ) {
        $columns = is_string($columns) ? $columns : ('`' . implode('`,`', $columns) . '`');
        $value = implode(',', array_fill(0, count($values), '?'));
        $comment = $this->getComment($function);

        $sql = "insert into $table ($columns) values($value) $comment";

        $insertId = -1;
        $stmt = $this->query(function (\mysqli $mysqli) use ($sql, $values, &$insertId) {
            $stmt = $this->prepare($sql, $mysqli);
            $types = $this->buildMysqliParamTypes($values);
            $bindValue = array_values($values);
            $this->bind($stmt, $sql, $mysqli, $types, $bindValue);
            $this->execute($stmt, $sql, $mysqli);
            $insertId = $stmt->insert_id;

            return $stmt;
        }, $sql);

        $stmt->free_result();

        return $insertId;
    }

    private function bind(\mysqli_stmt $stmt, string $sql, \mysqli $mysqli, $types, &$binds)
    {
        $result = $stmt->bind_param($types, ...$binds);
        if ($result === false) {
            throw new DbException(
                'bind is failed',
                ' bind is failed: ' . $sql . ' ' . $mysqli->error . ' ' . $mysqli->errno,
                $mysqli->errno
            );
        }
    }

    private function prepare(string $sql, \mysqli $mysqli): \mysqli_stmt
    {
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            throw new DbException(
                'prepare is failed',
                ' prepare is failed: ' . $sql . ' ' . $mysqli->error . ' ' . $mysqli->errno,
                $mysqli->errno
            );
        }

        return $stmt;
    }

    private function execute(\mysqli_stmt $stmt, string $sql, \mysqli $mysqli): void
    {
        $result = $stmt->execute();
        if ($result === false) {
            throw new DbException(
                'execute is failed',
                ' execute is failed: ' . $sql . ' ' . $mysqli->error . ' ' . $mysqli->errno,
                $mysqli->errno
            );
        }
    }

    public function insertOne()
    {

    }

    public function insert1D(
        string $table,
        array|string $columns = [],
        array $values = [],
        int $type = self::D1D,
        string $function = '',
    ): array {
        if (count($values) === 0) {
            return [];
        }

        if (count($values) % count($columns) !== 0) {
            throw new DbException(
                'wrong value size for insert',
                'wrong values size: val=' . count($values) . ' column=' . count($columns)
            );
        }

        $valueChunks = array_chunk($values, count($columns));
        $sqlValues = [];
        $types = '';
        foreach ($valueChunks as $valueChunk) {
            $sqlValues[] = implode(',', array_fill(0, count($valueChunk), '?'));
            $types .= $this->buildMysqliParamTypes($valueChunk);
        }

        $sqlValues = '(' . implode('),(', $sqlValues) . ')';
        $columns = is_string($columns) ? $columns : ('`' . implode('`,`', $columns) . '`');
        $comment = $this->getComment($function);
        $sql = "insert into $table ($columns) values $sqlValues $comment";
        $insertId = -1;

        $stmt = $this->query(function (\mysqli $mysqli) use ($sql, $types, $values, &$insertId) {
            $stmt = $this->prepare($sql, $mysqli);
            $this->bind($stmt, $sql, $mysqli, $types, $values);
            $this->execute($stmt, $sql, $mysqli);

            $insertId = $mysqli->insert_id;

            return $stmt;
        }, $sql);
        $stmt->free_result();

        return $insertId;
    }

    public function insert2D(
        string $table,
        array|string $columns = [],
        array $values = [],
        string $function = ''
    ): array {
        if (count($values) === 0) {
            throw new DbEmptyDataException('empty data for insert', 'empty data for insert 2d');
        }

        $types = $this->buildMysqliParamTypes($values[0]);
        $sqlValue = implode(',', array_fill(0, count($values[0]), '?'));

        $columns = is_string($columns) ? $columns : ('`' . implode('`,`', $columns) . '`');
        $comment = $this->getComment($function);
        $sql = "insert into $table ($columns) values ($sqlValue) $comment";

        $insertIds = [];

        $this->query(function (\mysqli $mysqli) use ($sql, $types, $values, &$insertIds) {
            $stmt = $this->prepare($sql, $mysqli);
            $params = [];
            foreach ($values[0] as &$v) {
                $params[] = $v;
            }

            $this->bind($stmt, $sql, $mysqli, $types, $params);

            foreach ($values as $value) {
                foreach ($value as $k => &$v) {
                    $params[$k] = $v;
                }
                $stmt->execute();
                $insertIds[] = $mysqli->insert_id;
            }

            return $stmt;
        }, $sql);

        return $insertIds;
    }

    public function insert2DTransaction(
        string $table,
        array $columns = [],
        array $values = [],
        string $function = ''
    ) {
        $mysqli = $this->getMysqlRaw();
        $mysqli->autocommit(false);
        $mysqli->begin_transaction();
        $this->insert2D($table, $columns, $values, $function);
        $mysqli->commit();
        $mysqli->autocommit(true);
    }

    /**
     * @param int $flags
     * @param string|null $name
     * @return void
     * @throws \Exception
     */
    public function commit(int $flags = 0, string|null $name = null): void
    {
        try {
            $this->getMysqlRaw()->commit($flags, $name);
        } catch (\mysqli_sql_exception $ex) {
            throw new DbException(
                'error to commit',
                'error to commit ' . $ex->getMessage(),
                $ex->getCode(),
                $ex
            );
        }
    }

    /**
     * @param int $flags
     * @param string|null $name
     * @return void
     * @throws \Exception
     */
    public function rollback(int $flags = 0, string|null $name = null): void
    {
        try {
            //  $this->cleanup($this->getMysqlRaw());
            $this->getMysqlRaw()->rollback($flags, $name);
        } catch (\mysqli_sql_exception $ex) {
            throw new DbException(
                'error to rollback',
                'error to rollback ' . $ex->getMessage(),
                $ex->getCode(),
                $ex
            );
        }
    }

//    public function cleanup(\mysqli $mysqli): void
//    {
//        $mysqli->stmt_init();
//        do {
//            $result = $mysqli->store_result();
//            if ($result) {
//                $result->free();
//            }
//        } while ($mysqli->more_results() && $mysqli->next_result());
//    }

    public function query(callable $cb, string $sql): mixed
    {
        $mysqli = $this->getMysqlRaw();
        $stmt = null;
        $deadlockEx = null;
        $tryCount = 1;
        $isDeadlock = false;
        for ($tryIndex = 0; $tryIndex < $tryCount; $tryIndex++) {
            try {
                $stmt = $cb($mysqli);
            } catch (\mysqli_sql_exception $ex) {
                if ($ex->getCode() === 1062) {
                    $text = sprintf('Duplicate raw for sql "%s". Msg "%s".', $sql, $ex->getMessage());
                    throw new DuplicateRowDbException('duplicate row', $text, $ex->getCode(), $ex);
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
                    throw new DbReconnectException('connection is lost', 'connection is lost', $ex->getCode());
                }

                $text = sprintf('Error execute sql "%s". Msg "%s"', $sql, $ex->getMessage());
                throw new DbException('db error', $text, $ex->getCode(), $ex);
            } catch (\Throwable $ex) {
                throw new DbUnknownException('unknown db error', $ex->getMessage(), $ex->getCode(), $ex);
            }

            $deadlockEx = null;
            break;
        }

        if ($deadlockEx !== null) {
            $text = sprintf('deadlock for sql "%s". Msg "%s".', $sql, $deadlockEx->getMessage());
            throw new DeadlockException('deadlock', $text, $deadlockEx->getCode(), $deadlockEx);
        }

        return $stmt;
    }

    private function getComment(string $function): string
    {
        $requestId = $this->requestId ?? '';
        if (empty($function) && empty($requestId)) {
            return '';
        }

        $comment = ' #';
        if (empty($function)) {
            $comment .= ' ' . $function;
        }
        if (empty($requestId)) {
            $comment .= ' ' . $requestId;
        }

        return $comment;
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
        $mysqli = $this->getMysqlRaw();

        $stmt = $this->query(function () use ($sql, $params, $mode, $function, $mysqli) {
            if (empty($params)) {
                return $mysqli->query($sql, $mode);
            }

            $order = [];
            $sql = preg_replace_callback('/:p_[\w_]+/', function ($matches) use (&$order) {
                $order[] = substr($matches[0], 1);
                return '?';
            }, $sql);

            $sql .= $this->getComment($function);
            echo $sql, PHP_EOL;

            $types = '';
            $binds = [];
            foreach ($order as $name) {
                $param = $params[$name];
                list($value, $type) = is_array($param) ? $param : [$param, 's'];
                $types .= $type;
                $binds[] = $value;
            }

            $stmt = $this->prepare($sql, $mysqli);
            $this->bind($stmt, $sql, $mysqli, $types, $binds);
            $this->execute($stmt, $sql, $mysqli);
            return $stmt;
        }, $sql);


        if ($stmt instanceof \mysqli_result) {
            return new Fetch($stmt, $mysqli);
        }

        if (is_bool($stmt)) {
            return null;
        }

        $result = $stmt->get_result();
        if (is_bool($result)) {
            $stmt->free_result();
            return null;
        }

        return new Fetch($result, $mysqli);
    }

    public function escapeString(string $data): string
    {
        return $this->getMysqlRaw()->real_escape_string($data);
    }
}
