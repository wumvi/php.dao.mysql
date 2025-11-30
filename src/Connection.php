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
    public const int ARRAY_1D = 1;
    public const int ARRAY_2D = 2;

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
            throw new DbConnectException(
                Consts::CONNECTION_WRONG_URL_CONNECT,
                'db connect wrong. Check url ' . $url
            );
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

    public function phpTypeToMysqli($value): string
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

    private function prepExec($sql, array $bind): \mysqli_stmt
    {
        return $this->query(function (\mysqli $mysqli) use ($sql, $bind) {
            $stmt = $this->prepare($sql, $mysqli);
            if (!empty($bind)) {
                $types = $this->buildMysqliParamTypes($bind);
                $values = array_values($bind);
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

        /** @var \mysqli_result $result */
        $result = $stmt->get_result();

        $fetch = new Fetch($result, $stmt->affected_rows);

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

        $stmt = $this->query(function (\mysqli $mysqli) use ($sql, $set, $where) {
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

        return $stmt->affected_rows;
    }

    public function delete(
        string $table,
        array $where = [],
        string $function = ''
    ): int {
        $sql = 'delete from ' . $table;
        $sql .= $this->getCondition($where);
        $sql .= $this->getComment($function);
        $stmt = $this->prepExec($sql, $where);

        return $stmt->affected_rows;
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

    public function insertSingle(
        string $table,
        array $data = [],
        string $function = '',
    ) {
        if (count($data) === 0) {
            return -1;
        }

        $mysqli = $this->getMysqlRaw();
        $columns = array_keys($data);
        $values = array_values($data);

        $sql = 'insert into ' . $table;
        $sql .= '(`' . implode('`,`', $columns) . '`)';
        $sql .= 'values (' . implode(',', array_fill(0, count($values), '?')) . ')';
        $sql .= $this->getComment($function);

        $stmt = $this->prepExec($sql, $values);
        $stmt->free_result();

        return $mysqli->insert_id;
    }

    public function insert1DBigBind(
        string $table,
        array|string $columns = [],
        array $values = [],
        string $function = '',
    ): void {
        if (count($values) === 0) {
            return;
        }

        $blockCount = count($values) / count($columns);

        $sql = 'insert into ' . $table;
        $sql .= '(`' . implode('`,`', $columns) . '`) values';
        $tplValue = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        for ($i = 0; $i < $blockCount; $i++) {
            $sql .= $tplValue . ',';
        }
        // убираем лишнюю запятую в конце
        $sql = substr($sql, 0, strlen($sql) - 1);
        $comment = $this->getComment($function);
        $sql .= $comment;

        $this->prepExec($sql, $values);
    }

    public function convert2Dto1D(array $columns, array $values): array
    {
        $values1D = [];
        foreach ($values as $item) {
            $values1D = array_merge($values1D, $item);
        }

        if (count($values1D) % count($columns) !== 0) {
            throw new DbException(
                'wrong value size for insert',
                'wrong values size: values1D=' . count($values1D) . ' column=' . count($columns)
            );
        }

        return $values1D;
    }

    public function convert1Dto2D(array $columns, array $values): array
    {
        if (count($values) % count($columns) !== 0) {
            throw new DbException(
                'wrong value size for insert',
                'wrong values size: values=' . count($values) . ' column=' . count($columns)
            );
        }

        return array_chunk($values, count($columns));
    }

    public function insert2DMultiBind(
        string $table,
        array $columns = [],
        array $values = [],
        string $function = ''
    ): array {
        if (count($values) === 0) {
            return [];
        }

        $mysqli = $this->getMysqlRaw();
        $mysqli->autocommit(false);
        $mysqli->begin_transaction();

        $types = $this->buildMysqliParamTypes($values[0]);
        $sqlValue = implode(',', array_fill(0, count($values[0]), '?'));

        $columns = '`' . implode('`,`', $columns) . '`';
        $comment = $this->getComment($function);
        $sql = "insert into $table ($columns) values ($sqlValue) $comment";

        $insertIds = [];

        $this->query(function (\mysqli $mysqli) use ($sql, $types, $values, &$insertIds) {
            $stmt = $this->prepare($sql, $mysqli);
            $params = array_fill(0, count($values[0]), null);
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

        $mysqli->commit();
        $mysqli->autocommit(true);

        return $insertIds;
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

    public function cleanup(\mysqli $mysqli): void
    {
        $mysqli->stmt_init();
        do {
            $result = $mysqli->store_result();
            if ($result) {
                $result->free_result();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
    }

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

    public function getComment(string $function): string
    {
        $requestId = $this->requestId ?? '';
        if (empty($function) && empty($requestId)) {
            return '';
        }

        $comment = ' #';
        if (!empty($function)) {
            $comment .= ' ' . $function;
        }
        if (!empty($requestId)) {
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

        /** @var \mysqli_result|\mysqli_stmt $handle */
        $handle = $this->query(function () use ($sql, $params, $mode, $function, $mysqli) {
            if (empty($params)) {
                return $mysqli->query($sql, $mode);
            }

            $order = [];
            $sql = preg_replace_callback('/:p_[\w_]+/', function ($matches) use (&$order) {
                $order[] = substr($matches[0], 1);
                return '?';
            }, $sql);

            $sql .= $this->getComment($function);

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


        if ($handle instanceof \mysqli_result) {
            return new Fetch($handle, $mysqli->affected_rows);
        }

        if (is_bool($handle)) {
            return null;
        }

        $result = $handle->get_result();
        if (is_bool($result)) {
            $handle->free_result();
            return null;
        }

        return new Fetch($result, $handle->affected_rows);
    }

    public function escapeString(string $data): string
    {
        return $this->getMysqlRaw()->real_escape_string($data);
    }
}
