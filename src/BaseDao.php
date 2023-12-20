<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql;

class BaseDao
{
    private string $requestId;
    /** @var Connection[] */
    private array $masters = [];
    /** @var Connection[] */
    private array $slaves = [];

    public function __construct(
        array $masters = [],
        array $slaves = [],
        string $requestId = ''
    ) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        foreach ($masters as $name => $uri) {
            $this->masters[] = new Connection($name, $uri);
        }
        foreach ($slaves as $name => $uri) {
            $this->slaves[] = new Connection($name, $uri);
        }
        $this->requestId = $requestId;
    }

    public function close()
    {
        array_map(fn($con) => $con->close(), $this->masters);
        array_map(fn($con) => $con->close(), $this->slaves);
    }

    private function getReadyConnection(array $conn): Connection
    {
        $count = count($conn);
        if ($count === 1) {
            return $conn[0];
        }

        foreach ($conn as $con) {
            if ($con->isCreated()) {
                return $con;
            }
        }

        return $conn[mt_rand(0, $count - 1)];
    }


    private function getConnection(bool $isSlave): Connection
    {
        $count = count($this->slaves);
        if ($isSlave && $count > 0) {
            return $this->getReadyConnection($this->slaves);
        }

        $count = count($this->masters);
        if ($count === 0) {
            throw new DbException(DbException::CONNECTION_IS_EMPTY);
        }
        return $this->getReadyConnection($this->masters);
    }

    public function getMysql(bool $isSlave): \mysqli
    {
        $con = $this->getConnection($isSlave);
        return $con->getMysqlConnection();
    }

    public function insert(string $table, array $data): int
    {
        $conn = $this->getConnection(false);
        $mysql = $conn->getMysqlConnection();

        $keys = array_keys($data);
        $fields = '`' . implode('`,`', $keys) . '`';
        $sql = 'INSERT INTO ' . $table . ' (' . $fields . ') values ';
        $count = count($data[$keys[0]]);
        for ($i = 0; $i < $count; $i++) {
            $values = [];
            foreach ($keys as $field) {
                $values[] = Utils::convert($data[$field][$i], $mysql);
            }
            $sql .= ' (' . implode(',', $values) . '),';
        }
        $sql = substr($sql, 0, strlen($sql) - 1);
        try {
            $mysql->query($sql);
            return $mysql->insert_id;
        } catch (\mysqli_sql_exception $ex) {
            $msg = sprintf('Error execute sql "%s". Msg "%s".', $sql, $ex->getMessage());
            if (stripos($ex->getMessage(), 'duplicate') !== false) {
                throw new DuplicateRowDbException($msg);
            }
            throw new DbException($msg);
        }
    }

    /**
     * @throws DbException
     */
    public function call(
        string $sql,
        array $params = [],
        bool $isSlave = false,
        string $function = ''
    ): Fetch {
        $conn = $this->getConnection($isSlave);
        $mysql = $conn->getMysqlConnection();

        if (!empty($params)) {
            $order = [];
            $sql = preg_replace_callback('/:[\w_]+/', function ($matches) use (&$order) {
                $order[] = substr($matches[0], 1);
                return '?';
            }, $sql);
            $requestId = $this->requestId ? ' # ' . $this->requestId : '';
            $sql .= $requestId;

            $types = '';
            $binds = [];
            foreach ($order as $name) {
                $param = $params[$name];
                list($value, $type) = is_array($param) ? $param : [$param, 's'];
                $types .= $type;
                $binds[] = $value;
            }
        }

        try {
            if (empty($params)) {
                $result = $mysql->query($sql);
            } else {
                $stmt = $mysql->prepare($sql);
                $stmt->bind_param($types, ...$binds);
                $stmt->execute();
                $result = $stmt->get_result();
            }
        } catch (\mysqli_sql_exception $ex) {
            $msg = sprintf('Error execute sql "%s". Msg "%s".', $sql, $ex->getMessage());
            if (stripos($ex->getMessage(), 'duplicate') !== false) {
                throw new DuplicateRowDbException($msg);
            }
            throw new DbException($msg);
        } catch (\Throwable $ex) {
            throw new DbException($ex->getMessage());
        }

        return new Fetch($result, $mysql);
    }
}
