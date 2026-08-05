<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Forward-only SQL migration runner.
 *
 * Managed Postgres (RDS / Cloud SQL / Azure Database) has no equivalent of
 * `docker compose down -v`, so the old "recreate the volume to pick up schema
 * changes" workflow does not exist there. Every schema change must arrive as an
 * ordered, recorded migration instead.
 *
 * Each file runs inside its own transaction; a failure rolls that file back and
 * stops the run, leaving earlier migrations applied and recorded.
 */
class Migrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationsPath
    ) {
    }

    /**
     * @return list<string> the migrations applied by this run
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static fn (string $m) => null;

        $this->ensureRegistry();

        $applied = $this->appliedVersions();
        $pending = array_values(array_filter(
            $this->availableMigrations(),
            fn (string $file) => !in_array($this->versionOf($file), $applied, true)
        ));

        if ($pending === []) {
            $log('Nothing to migrate; database is up to date.');
            return [];
        }

        $ran = [];
        foreach ($pending as $file) {
            $version = $this->versionOf($file);
            $sql     = file_get_contents($this->migrationsPath . '/' . $file);

            if ($sql === false) {
                throw new RuntimeException("Could not read migration {$file}");
            }

            $log("Applying {$file} ...");
            $this->db->beginTransaction();

            try {
                $this->db->exec($sql);
                $stmt = $this->db->prepare(
                    'INSERT INTO schema_migrations (version, filename) VALUES (?, ?)'
                );
                $stmt->execute([$version, $file]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException(
                    "Migration {$file} failed and was rolled back: {$e->getMessage()}",
                    0,
                    $e
                );
            }

            $ran[] = $file;
            $log("  applied {$file}");
        }

        return $ran;
    }

    /** @return list<array{version: string, filename: string, applied_at: string}> */
    public function status(): array
    {
        $this->ensureRegistry();
        $applied = $this->appliedVersions();

        $rows = [];
        foreach ($this->availableMigrations() as $file) {
            $version  = $this->versionOf($file);
            $rows[] = [
                'version'  => $version,
                'filename' => $file,
                'applied'  => in_array($version, $applied, true),
            ];
        }

        return $rows;
    }

    private function ensureRegistry(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(20) PRIMARY KEY,
                filename   VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    /** @return list<string> */
    private function appliedVersions(): array
    {
        return $this->db->query('SELECT version FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return list<string> filenames, lexically ordered (001_, 002_, ...) */
    private function availableMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException("Migrations directory not found: {$this->migrationsPath}");
        }

        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);

        return array_values($names);
    }

    private function versionOf(string $filename): string
    {
        return explode('_', $filename, 2)[0];
    }
}
