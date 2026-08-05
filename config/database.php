<?php

declare(strict_types=1);

/**
 * All values come from the environment. Nothing is defaulted to a real
 * credential -- the previous 'your_password_here' literal was a committed
 * secret-shaped value that silently became the password when DB_PASSWORD
 * was unset. See .env.example.
 */
$password = getenv('DB_PASSWORD');

if ($password === false || $password === '') {
    throw new RuntimeException(
        'DB_PASSWORD is not set. Export it, or run the app via docker compose (which injects it).'
    );
}

return [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'port'     => getenv('DB_PORT') ?: '5432',
    'dbname'   => getenv('DB_NAME') ?: 'robot_scheduler',
    'user'     => getenv('DB_USER') ?: 'postgres',
    'password' => $password,
];
