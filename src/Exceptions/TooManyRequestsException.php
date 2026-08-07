<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 429. Raised when a caller has spent its allowance for an endpoint.
 *
 * Carries the retry window so the client is told when to come back rather than
 * being left to guess; the front controller turns it into a Retry-After header.
 */
class TooManyRequestsException extends HttpException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds)
    {
        parent::__construct($message, 429);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function getContext(): array
    {
        return ['retry_after' => $this->retryAfterSeconds];
    }
}
