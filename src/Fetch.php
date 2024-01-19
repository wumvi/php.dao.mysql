<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Fetch
{
    private \mysqli_result|true $stmt;
    private \mysqli $mysql;

    private bool $isClose = false;

    public function __construct(\mysqli_result|true $stmt, \mysqli $mysql)
    {
        $this->stmt = $stmt;
        $this->mysql = $mysql;
    }

    public function __destruct()
    {
        $this->free();
    }

    public function getAffectedRows(): int
    {
        return $this->mysql->affected_rows;
    }

    public function fetchAll(): array
    {
        if ($this->stmt === true) {
            return [];
        }

        $data = $this->stmt->fetch_all(MYSQLI_ASSOC);
        $this->free();

        return $data;
    }

    public function getStmt(): \mysqli_result|true
    {
        return $this->stmt;
    }

    public function free(): void
    {
        if ($this->stmt === true || $this->isClose) {
            return;
        }

        $this->stmt->free();
        $this->isClose = true;
    }

    public function fetchOne(): array
    {
        if ($this->stmt === true) {
            return [];
        }

        $data = $this->stmt->fetch_assoc() ?: [];
        $this->free();

        return $data;
    }
}
