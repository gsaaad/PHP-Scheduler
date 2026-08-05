<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private PDO $pdo;

    public function __construct(?array $config = null)
    {
        $config ??= require __DIR__ . '/../config/database.php';

        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException(
                'The pdo_pgsql extension is not loaded. Enable it in php.ini, or run the app via docker compose.'
            );
        }

        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Throw rather than die() with the driver message: the front
            // controller logs the detail and returns a generic 500, so DSN and
            // credential hints never reach the browser.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
