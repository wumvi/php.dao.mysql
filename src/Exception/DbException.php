<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql\Exception;

class DbException extends \Exception
{
    public readonly string $publicMsg;

    public function __construct(
        string $publicMsg,
        string $message = '',
        $code = 0,
        \Throwable|null $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->publicMsg = $publicMsg;
    }
}