<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

use Wumvi\Dao\Mysql\Exception\DbConnectException;

class Mysql
{
    private string $dbName;
    private string $user;
    private string $password;
    private string $hostname;
    private readonly int $port;

    private \mysqli|null $mysql = null;

    private int $threadId = Consts::THREAD_ID;

    private bool $isAutocommit = false;

    private ?string $socket = null;
    private int $flag = 0;
    private int $deadLockTryCount = Consts::DEADLOCK_TRY_COUNT;

    public function __construct(string $dbConnect, string $name)
    {
        $raw = parse_url($dbConnect);
        if (!is_array($raw)) {
            throw new DbConnectException('wrong-url-connect');
        }

        if (isset($raw['query'])) {
            parse_str($raw['query'], $query);
            $this->isAutocommit = ($query['autocommit'] ?? '0') === '1';
            $this->socket = $query['socket'] ?? null;
            $this->flag = isset($query['flag']) ? (int)$query['flag'] : 0;
            $deadlockTryCount = (int)($query['deadlock-try-count'] ?? Consts::DEADLOCK_TRY_COUNT);
            $this->deadLockTryCount = max($deadlockTryCount, 1);
        }

        $this->dbName = trim($raw['path'] ?? 'main', ' /');
        $this->user = $raw['user'] ?? 'root';
        $this->password = $raw['pass'] ?? 'pwd';
        $this->hostname = $raw['host'] ?? '127.0.0.1';
        $this->port = $raw['port'] ?? Consts::DEFAULT_PORT;
    }

    public function getDeadLockTryCount(): int
    {
        return $this->deadLockTryCount;
    }

    public function getThreadId(): int
    {
        return $this->threadId;
    }

    public function getMysqlConnection(): \mysqli
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
                    $this->socket,
                    $this->flag
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

    public function reconnect(): \mysqli
    {
        $this->close();
        return $this->getMysqlConnection();
    }

    public function close()
    {
        try {
            $this->mysql?->close();
        } catch (\Throwable $ex) {

        } finally {
            $this->mysql = null;
        }
    }
}