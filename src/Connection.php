<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Connection
{
    private string $dbName;
    private string $user;
    private string $password;
    private string $hostname;
    private int $port;

    private \mysqli|null $mysql = null;

    public int $threadId = -1;

    public function __construct(string $name, string $dbConnect)
    {
        $raw = parse_url($dbConnect);

        if ($raw === false) {
            throw new \Exception('Wrong dbconnect');
        }

        if (!array_key_exists('host', $raw)) {
            throw new \Exception('Not found "host" in dbconnect');
        }

        $this->dbName = trim($raw['path'], ' /');
        $this->user = $raw['user'];
        $this->password = $raw['pass'];
        $this->hostname = $raw['host'];
        $this->port = $raw['port'];
    }

    public function isCreated()
    {
        return $this->mysql !== null;
    }

    public function getThreadId(): int
    {
        return $this->threadId;
    }

    public function getMysqlConnection(): \mysqli
    {
        if ($this->mysql === null) {
            $this->mysql = new \mysqli($this->hostname, $this->user, $this->password, $this->dbName, $this->port);
            if ($this->mysql->connect_errno) {
                $this->mysql = null;
                $msg = sprintf(
                    'Error to connect to mysql server %s@%s:%s:%s:%s',
                    $this->user,
                    $this->hostname,
                    $this->port,
                    $this->mysql->connect_errno,
                    $this->mysql->connect_error
                );
                throw new \Exception($msg);
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