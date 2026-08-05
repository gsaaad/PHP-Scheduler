<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found', ?Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous);
    }

    public static function robot(int|string $id): self
    {
        return new self("Robot {$id} not found");
    }

    public static function task(int|string $id): self
    {
        return new self("Task {$id} not found");
    }

    public static function schedule(int|string $id): self
    {
        return new self("Schedule {$id} not found");
    }
}
