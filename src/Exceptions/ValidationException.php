<?php

declare(strict_types=1);

namespace App\Exceptions;

class ValidationException extends HttpException
{
    /** @param array<string, string> $errors field name => message */
    public function __construct(private readonly array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getContext(): array
    {
        return ['errors' => $this->errors];
    }
}
