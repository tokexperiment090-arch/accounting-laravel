<?php

declare(strict_types=1);

namespace Liberu\AccountingSdk\Exception;

class ValidationException extends ApiException
{
    /** @param array<string, mixed> $errors */
    public function __construct(string $message, int $status, private array $errors = [])
    {
        parent::__construct($message, $status);
    }

    /** @return array<string, mixed> */
    public function errors(): array
    {
        return $this->errors;
    }
}
