<?php

declare(strict_types=1);

namespace Liberu\AccountingSdk\Exception;

class RateLimitException extends ApiException
{
    public function __construct(string $message, int $status, private ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
