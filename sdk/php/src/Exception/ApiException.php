<?php

declare(strict_types=1);

namespace Liberu\AccountingSdk\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
