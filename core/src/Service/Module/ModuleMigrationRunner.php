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
     * @return list<string> Namen der ausgeführten Migrationen.
     */
    public function runUp(string $moduleId, string $schema, string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);

        $connection = ConnectionManager::get('default');
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

            $up = $this->upPart((string)file_get_contents($file));

            try {
                $connection->transactional(function () use ($connection, $schema, $up, $moduleId, $name): void {
                    $connection->execute("SET LOCAL search_path TO $schema, core, public");
                    foreach ($this->statements($up) as $stmt) {
                        $connection->execute($stmt);
                    }
                    $connection->execute(
                        "INSERT INTO core.module_migrations_log (module_id, migration_name, status) "
                        . "VALUES (:m, :n, 'success')",
                        ['m' => $moduleId, 'n' => $name],
                    );
                });
                $executed[] = $name;
            } catch (Throwable $e) {
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
     * Modul-Schema aus und entfernt den Log-Eintrag.
     */
    public function runDown(string $moduleId, string $schema, string $migrationsDir, string $name): void
    {
        $file = rtrim($migrationsDir, '/') . '/' . $name;
        if (!is_file($file)) {
            return;
        }
        $down = $this->downPart((string)file_get_contents($file));

        $connection = ConnectionManager::get('default');
        $connection->transactional(function () use ($connection, $schema, $down, $moduleId, $name): void {
            $connection->execute("SET LOCAL search_path TO $schema, core, public");
            foreach ($this->statements($down) as $stmt) {
                $connection->execute($stmt);
            }
            $connection->execute(
                'DELETE FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
                ['m' => $moduleId, 'n' => $name],
            );
        });
    }

    public function isApplied(string $moduleId, string $name): bool
    {
        return ConnectionManager::get('default')->execute(
            'SELECT 1 FROM core.module_migrations_log WHERE module_id = :m AND migration_name = :n',
            ['m' => $moduleId, 'n' => $name],
        )->fetch() !== false;
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
