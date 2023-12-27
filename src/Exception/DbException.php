<?php
declare(strict_types=1);

namespace Wumvi\Dao\Mysql\Exception;

class DbException extends \Exception
{
    private string $text;

    public function __construct(string $text, string $error = '', $code = 0, \Throwable $previous = null)
    {
        parent::__construct($error, $code, $previous);
        $this->text = $text;
    }

    public function getText(): string
    {
        return $this->text;
    }
}