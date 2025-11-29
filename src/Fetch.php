<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Fetch
{
    public \mysqli_result $result {
        get {
            return $this->result;
        }
    }
    private \mysqli $mysql;

    private bool $isClose = false;

    public function __construct(\mysqli_result $result, \mysqli $mysql)
    {
        $this->result = $result;
        $this->mysql = $mysql;
    }

    public function __destruct()
    {
        if (!$this->isClose) {
            $this->result->free_result();
        }
    }

    public function getAffectedRows(): int
    {
        return $this->mysql->affected_rows;
    }

    public function free(): void
    {
        if ($this->isClose) {
            return;
        }

        $this->result->free_result();
        $this->isClose = true;
    }

    public function fetchOne(): array
    {
        if ($this->isClose) {
            return [];
        }

        $data = $this->result->fetch_assoc() ?: [];
        $this->result->free_result();
        $this->isClose = true;

        return $data;
    }

    public function fetchAll(): array
    {
        if ($this->isClose) {
            return [];
        }
        $data = $this->result->fetch_all(MYSQLI_ASSOC);
        $this->result->free_result();
        $this->isClose = true;

        return $data;
    }
}
