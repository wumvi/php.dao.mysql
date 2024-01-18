<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class Fetch
{
    private \mysqli_result|true $stmt;
    private \mysqli $mysql;

    public function __construct(\mysqli_result|true $stmt, \mysqli $mysql)
    {
        $this->stmt = $stmt;
        $this->mysql = $mysql;
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
        $this->stmt->free();

        if ($this->mysql->more_results()) {
            $this->closeResult();
        }

        return $data;
    }

    public function fetchOne(): array
    {
        if ($this->stmt === true) {
            return [];
        }

        $data = $this->stmt->fetch_assoc() ?: [];
        $this->stmt->free();
        // $this->closeResult();

        return $data;
    }

    private function closeResult(): void
    {
        do {
            $this->mysql->next_result();
            $this->mysql->more_results();
        } while ($this->mysql->more_results());
        $this->stmt->free();
    }
}
