<?php
declare(strict_types=1);

namespace Wumvi\MysqlDao;

class DbManager
{
    /**
     * @var array<mixed>
     */
    public static array $vars = [];
    private ?\mysqli $mysql = null;
    private bool $isPersistent;
    private string $url;


    public function __construct(string $url, bool $isPersistent = true)
    {
        $this->url = $url;
        $this->isPersistent = $isPersistent;
    }

    public function isConnected(): bool
    {
        return $this->mysql->ping();
    }

    public function disconnect(): void
    {
        if ($this->mysql !== null) {
            $this->mysql->close();
        }
    }

    /**
     * @return \mysqli
     *
     * @throws DbException
     */
    public function getConnection(): \mysqli
    {
        if ($this->mysql !== null) {
            return $this->mysql;
        }

        $url = $this->url;
        foreach (self::$vars as $var => $value) {
            $url = str_replace('{' . $var . '}', $value, $url);
        }
        $raw = parse_url($url);
        $dbName = trim($raw['path'], ' /');
        $user = $raw['user'];
        $password = $raw['pass'];
        $hostname = ($this->isPersistent ? 'p:' : '') . $raw['host'];
        $port = $raw['port'];
        $this->mysql = new \mysqli($hostname, $user, $password, $dbName, $port);
        if ($this->mysql->connect_errno) {
            $msg = sprintf(
                'Error to connect to mysql server %s@%s:%s:%s:%s',
                $user,
                $hostname,
                $port,
                $this->mysql->connect_errno,
                $this->mysql->connect_error
            );
            throw new DbException($msg);
        }

        return $this->mysql;
    }
}
