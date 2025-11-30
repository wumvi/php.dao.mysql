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

    private bool $isClose = false;

    public int $affectedRows {
        get {
            return $this->affectedRows;
        }
    }

    public function __construct(\mysqli_result $result, int $affectedRows)
    {
        $this->result = $result;
        $this->affectedRows = $affectedRows;
    }

    public function __destruct()
    {
        if (!$this->isClose) {
            $this->result->free_result();
        }
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
