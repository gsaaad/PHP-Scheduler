<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Migrations\Migrator;

$command  = $argv[1] ?? 'migrate';
$migrator = new Migrator(
    (new Database())->getConnection(),
    __DIR__ . '/../sql/migrations'
);

try {
    if ($command === 'status') {
        printf("%-10s %-45s %s\n", 'VERSION', 'FILE', 'APPLIED');
        foreach ($migrator->status() as $row) {
            printf("%-10s %-45s %s\n", $row['version'], $row['filename'], $row['applied'] ? 'yes' : 'NO');
        }
        exit(0);
    }

    if ($command !== 'migrate') {
        fwrite(STDERR, "Usage: php scripts/migrate.php [migrate|status]\n");
        exit(2);
    }

    $ran = $migrator->migrate(static fn (string $m) => print($m . "\n"));
    printf("Done. %d migration(s) applied.\n", count($ran));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
