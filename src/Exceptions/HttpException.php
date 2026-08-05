<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for exceptions the front controller can translate directly into an HTTP
 * status. Anything that is NOT an HttpException becomes a generic 500 with the
 * detail logged server-side rather than echoed to the client.
 */
abstract class HttpException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Extra fields merged into the JSON error body. */
    public function getContext(): array
    {
        return [];
    }
}
