<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use RuntimeException;
use Throwable;

/**
 * Runs module migrations. Module migrations are versioned SQL files
 * (`migrations/NNN_name.sql`) with a `-- @DOWN` separator (up above, down below).
 * They run transactionally in the module schema (mod_<key>) and are tracked in
 * core.module_migrations_log (Step 7, E21).
 *
 * (The core itself still uses cakephp/migrations; for modules the core-driven
 * SQL approach is more robust than per-module Phinx instances.)
 */
class ModuleMigrationRunner
{
    /**
     * Runs the not-yet-applied UP migrations of a package transactionally in the
     * module schema and records each in core.module_migrations_log.
     *
     * @return list<string> Names of the migrations that were run.
     */
    public function runUp(string $moduleId, string $schema, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        $connection = Db::privileged();
        $executed = [];

        foreach ($files as $file) {
            $name = basename($file);
            $done = $connection->execute(
                'SELECT 1 FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
                ['m' => $moduleId, 'n' => $name],
            )->fetch();
            if ($done) {
                continue;
            }

            $statements = $this->statements($this->upPart((string)file_get_contents($file)));

            try {
                $connection->transactional(function () use ($connection, $schema, $statements, $moduleId, $name): void {
                    $connection->execute("SET LOCAL search_path TO $schema, core, public");
                    foreach ($statements as $stmt) {
                        $connection->execute($stmt);
                    }
                    $connection->execute(
                        "INSERT INTO core.module_migrations_log (module_id, migration_name, status) VALUES (:m, :n, 'success')",
                        ['m' => $moduleId, 'n' => $name],
                    );
                });
                $executed[] = $name;
            } catch (Throwable $e) {
                // No 'failed' log entry (it would cause a unique conflict on
                // retry); the failed migration transaction has already been rolled back.
                throw new RuntimeException("Modul-Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
        }

        return $executed;
    }

    /**
     * Runs the down operation of an already applied module migration (rollback).
     * Reads the @DOWN part from the package file, runs it in the module schema
     * and removes the log entry.
     */
    public function runDown(string $moduleId, string $schema, string $migrationsDir, string $name): void
    {
        $file = rtrim($migrationsDir, '/') . '/' . $name;
        if (!is_file($file)) {
            return;
        }
        $statements = $this->statements($this->downPart((string)file_get_contents($file)));
        // An empty @DOWN section is not a valid rollback: deleting the tracking
        // entry WITHOUT actually reverting would leave the state inconsistent (a
        // re-update would then fail with "object already exists").
        if ($statements === []) {
            throw new RuntimeException("Migration $name hat keine @DOWN-Sektion – Rückbau nicht möglich.");
        }

        $connection = Db::privileged();
        try {
            $connection->transactional(function () use ($connection, $schema, $statements, $moduleId, $name): void {
                $connection->execute("SET LOCAL search_path TO $schema, core, public");
                foreach ($statements as $stmt) {
                    $connection->execute($stmt);
                }
                $connection->execute(
                    'DELETE FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
                    ['m' => $moduleId, 'n' => $name],
                );
            });
        } catch (Throwable $e) {
            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException("Down-Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
        }
    }

    public function isApplied(string $moduleId, string $name): bool
    {
        return Db::privileged()->execute(
            'SELECT 1 FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
            ['m' => $moduleId, 'n' => $name],
        )->fetch() !== false;
    }

    /**
     * Lists the not-yet-applied migrations of a package WITHOUT running them
     * (migration preview, ch. 24.13/28.8.1).
     *
     * @return list<string> File names of the pending migrations (sorted).
     */
    public function listPending(string $moduleId, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!$this->isApplied($moduleId, $name)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    private function upPart(string $sql): string
    {
        $parts = preg_split('/^\s*--\s*@DOWN\s*$/mi', $sql, 2);

        return $parts[0] ?? $sql;
    }

    private function downPart(string $sql): string
    {
        $parts = preg_split('/^\s*--\s*@DOWN\s*$/mi', $sql, 2);

        return $parts[1] ?? '';
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        // First strip comment lines, so a leading comment does not swallow the
        // whole statement; then split into statements on ';'.
        $clean = [];
        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $clean[] = $line;
        }

        $out = [];
        foreach (explode(';', implode("\n", $clean)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }

        return $out;
    }
}
