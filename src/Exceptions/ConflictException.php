<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * The request was well-formed but cannot be satisfied against current state --
 * an overlapping booking, a robot in maintenance, a missing capability.
 */
class ConflictException extends HttpException
{
    public function __construct(string $message = 'Request conflicts with current state', ?Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);
    }
}
