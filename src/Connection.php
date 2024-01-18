<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbConnectException;

class Connection
{
    private string $dbName;
    private string $user;
    private string $password;
    private string $hostname;
    private readonly int $port;

    private \mysqli|null $mysql = null;

    private int $threadId;

    private bool $isAutocommit = false;

    private ?string $socket = null;
    private int $flag = 0;
    private int $deadLockTryCount = Consts::DEADLOCK_TRY_COUNT;

    public function __construct(string $name, string $dbConnect)
    {
        $raw = parse_url($dbConnect);

        if ($raw === false) {
            throw new DbConnectException('Wrong dbconnect');
        }

        if (!array_key_exists('host', $raw)) {
            throw new DbConnectException('Not found "host" in dbconnect');
        }

        if (isset($raw['query'])) {
            parse_str($raw['query'], $query);
            $this->isAutocommit = ($query['autocommit'] ?? '0') === '1';
            $this->socket = $query['socket'] ?? null;
            $this->flag = isset($query['flag']) ? (int)$query['flag'] : 0;
            $deadlockTryCount = (int)($query['deadlock-try-count'] ?? Consts::DEADLOCK_TRY_COUNT);
            $this->deadLockTryCount = max($deadlockTryCount, Consts::DEADLOCK_TRY_COUNT);
        }

        $this->dbName = trim($raw['path'] ?? 'main', ' /');
        $this->user = $raw['user'] ?? 'root';
        $this->password = $raw['pass'] ?? 'pwd';
        $this->hostname = $raw['host'] ?? '127.0.0.1';
        $this->port = $raw['port'] ?? 3306;

        $this->threadId = -1;
    }

    public function getDeadLockTryCount(): int
    {
        return $this->deadLockTryCount;
    }

    public function getThreadId(): int
    {
        return $this->threadId;
    }

    public function isCreated()
    {
        return $this->mysql !== null;
    }

    public function getMysqlConnection(): \mysqli
    {
        if ($this->mysql === null) {
            $this->mysql = mysqli_init();

            if (!$this->isAutocommit && !$this->mysql->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 0')) {
                throw new DbConnectException('Setting MYSQLI_INIT_COMMAND failed');
            }
            $status = $this->mysql->real_connect(
                $this->hostname,
                $this->user,
                $this->password,
                $this->dbName,
                $this->port,
                $this->socket,
                $this->flag
            );
            if (!$status) {
                $this->mysql = null;
                $msg = sprintf(
                    Consts::ERROR_CONNECT_MSG,
                    $this->user,
                    $this->hostname,
                    $this->port,
                    $this->mysql->connect_errno,
                    $this->mysql->connect_error
                );
                throw new DbConnectException($msg);
            }

            $this->threadId = $this->mysql->thread_id;
            register_shutdown_function(function () {
                $this->close();
            });
        }

        return $this->mysql;
    }

    public function close()
    {
        try {
            $this->mysql?->close();
        } catch (\Throwable $ex) {

        }
    }

    public function escapeString(string $data)
    {
        return $this->mysql->real_escape_string($data);
    }
}