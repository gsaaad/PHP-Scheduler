<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/** 401: no valid credentials were presented. */
class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required', ?Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}
