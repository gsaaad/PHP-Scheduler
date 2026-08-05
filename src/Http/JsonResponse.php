<?php

declare(strict_types=1);

namespace App\Http;

class JsonResponse
{
    /**
     * Every response goes out as JSON with an explicit Content-Type -- the old
     * store() and 404 branches omitted it.
     */
    public static function send(mixed $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, int $status, array $context = []): void
    {
        self::send(['error' => $message] + $context, $status);
    }
}
