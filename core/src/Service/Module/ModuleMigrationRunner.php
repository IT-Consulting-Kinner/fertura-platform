<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Führt Modul-Migrationen aus. Modul-Migrationen sind versionierte SQL-Dateien
 * (`migrations/NNN_name.sql`) mit einem `-- @DOWN`-Trenner (oben up, unten down).
 * Sie laufen transaktional im Modul-Schema (mod_<key>) und werden in
 * core.module_migrations_log getrackt (Step 7, E21).
 *
 * (Der Core selbst nutzt weiterhin cakephp/migrations; für Module ist die
 * Core-gesteuerte SQL-Variante robuster als per-Modul-Phinx-Instanzen.)
 */
class ModuleMigrationRunner
{
    /**
     * @param ?string $roleDsn Wenn gesetzt, läuft die Migrations-DDL über eine als
     *   **eingeschränkte Modul-Login-Rolle** authentifizierte Verbindung (PDO-DSN)
     *   statt als Superuser (Out-of-Process-Isolation, Kap. 23.16.2). Als echte
     *   Login-Rolle kann eine bösartige Migration den Core **nicht** beschädigen:
     *   `RESET ROLE`/`SET ROLE`/`SET SESSION AUTHORIZATION` führen nicht zu
     *   Superuser zurück (kein `SET LOCAL ROLE` auf einer Superuser-Session). Der
     *   Tracking-Insert läuft auf der Superuser-Verbindung (die Rolle hat keinen
     *   Zugriff auf core.*).
     * @return list<string> Namen der ausgeführten Migrationen.
     */
    public function runUp(string $moduleId, string $schema, string $migrationsDir, ?string $roleDsn = null): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        $connection = \App\Infrastructure\Db::privileged();
        $rolePdo = $roleDsn !== null ? $this->roleConnection($roleDsn) : null;
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
                if ($rolePdo !== null) {
                    // DDL als eingeschränkte Login-Rolle (kein Superuser-Code).
                    $rolePdo->beginTransaction();
                    $rolePdo->exec("SET LOCAL search_path TO $schema, core, public");
                    foreach ($statements as $stmt) {
                        $rolePdo->exec($stmt);
                    }
                    $rolePdo->commit();
                    // Tracking als Superuser (Rolle hat keinen core.*-Zugriff).
                    $connection->execute(
                        "INSERT INTO core.module_migrations_log (module_id, migration_name, status) VALUES (:m, :n, 'success')",
                        ['m' => $moduleId, 'n' => $name],
                    );
                } else {
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
                }
                $executed[] = $name;
            } catch (Throwable $e) {
                if ($rolePdo !== null && $rolePdo->inTransaction()) {
                    $rolePdo->rollBack();
                }
                // Kein 'failed'-Log (sonst Unique-Konflikt beim Retry); die
                // fehlgeschlagene Migrationstransaktion ist bereits zurückgerollt.
                throw new RuntimeException("Modul-Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
            }
        }

        return $executed;
    }

    /**
     * Führt die down-Operation einer bereits angewendeten Modul-Migration aus
     * (Rollback). Liest den @DOWN-Teil aus der Paketdatei, führt ihn im
     * Modul-Schema aus und entfernt den Log-Eintrag. Bei isolierten Modulen
     * (`$roleDsn`) läuft auch die down-DDL als Login-Rolle.
     */
    public function runDown(string $moduleId, string $schema, string $migrationsDir, string $name, ?string $roleDsn = null): void
    {
        $file = rtrim($migrationsDir, '/') . '/' . $name;
        if (!is_file($file)) {
            return;
        }
        $statements = $this->statements($this->downPart((string)file_get_contents($file)));
        // Eine leere @DOWN-Sektion ist kein gültiger Rückbau: den Tracking-Eintrag
        // OHNE tatsächliches Zurücksetzen zu löschen würde den Stand inkonsistent
        // machen (Re-Update scheitert dann an „Objekt existiert bereits").
        if ($statements === []) {
            throw new RuntimeException("Migration $name hat keine @DOWN-Sektion – Rückbau nicht möglich.");
        }

        $connection = \App\Infrastructure\Db::privileged();
        $rolePdo = $roleDsn !== null ? $this->roleConnection($roleDsn) : null;
        try {
            if ($rolePdo !== null) {
                $rolePdo->beginTransaction();
                $rolePdo->exec("SET LOCAL search_path TO $schema, core, public");
                foreach ($statements as $stmt) {
                    $rolePdo->exec($stmt);
                }
                $rolePdo->commit();
                $connection->execute(
                    'DELETE FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
                    ['m' => $moduleId, 'n' => $name],
                );
            } else {
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
            }
        } catch (Throwable $e) {
            if ($rolePdo !== null && $rolePdo->inTransaction()) {
                $rolePdo->rollBack();
            }
            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException("Down-Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
        }
    }

    /** Verbindung als eingeschränkte Modul-Login-Rolle (für Migrationen-als-Rolle). */
    private function roleConnection(string $dsn): \PDO
    {
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function isApplied(string $moduleId, string $name): bool
    {
        return \App\Infrastructure\Db::privileged()->execute(
            'SELECT 1 FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
            ['m' => $moduleId, 'n' => $name],
        )->fetch() !== false;
    }

    /**
     * Listet die noch nicht angewendeten Migrationen eines Pakets, OHNE sie
     * auszuführen (Migrationsvorschau, Kap. 24.13/28.8.1).
     *
     * @return list<string> Dateinamen der ausstehenden Migrationen (sortiert).
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
        // Zuerst Kommentarzeilen entfernen, damit ein führender Kommentar nicht
        // die ganze Anweisung verschluckt; dann an ';' in Anweisungen trennen.
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
