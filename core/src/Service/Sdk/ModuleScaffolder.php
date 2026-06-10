<?php
declare(strict_types=1);

namespace App\Service\Sdk;

use RuntimeException;

/**
 * Modul-Scaffolder (Programm Tier-3, P16): erzeugt ein lauffähiges Modul-Gerüst
 * (Manifest, Beispiel-API-Endpunkt, Migration, README), das den
 * {@see ManifestLinter} sauber besteht — beschleunigt Drittanbieter-Module.
 */
class ModuleScaffolder
{
    /**
     * @return list<string> erzeugte Dateien (relativ)
     */
    public function scaffold(string $key, string $namespace, string $targetDir): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new RuntimeException('Modul-Key ungültig (erlaubt: [a-z][a-z0-9_]*).');
        }
        $namespace = trim($namespace, '\\') ?: 'Acme\\Module';
        $base = rtrim($targetDir, '/') . '/' . $key;
        if (is_dir($base)) {
            throw new RuntimeException("Zielverzeichnis existiert bereits: $base");
        }
        @mkdir($base . '/src', 0o775, true);
        @mkdir($base . '/migrations', 0o775, true);

        $manifest = [
            'id' => $key,
            'name' => ucwords(str_replace('_', ' ', $key)),
            'version' => '1.0.0',
            'type' => 'main',
            'edition' => 'free',
            'description' => 'Generiertes Modul-Gerüst.',
            'publisher' => 'Acme',
            'php_namespace' => $namespace,
            'core_compatibility' => '>=1.0.0 <2.0.0',
            'requires_license' => false,
            'dependencies' => [],
            'contracts_used' => [],
            'api_routes' => [[
                'method' => 'GET',
                'path' => '/ping',
                'class' => $namespace . '\\PingEndpoint',
                'summary' => 'Antwortet mit pong.',
            ]],
            'permissions' => [],
        ];

        $files = [];
        $write = static function (string $rel, string $content) use ($base, &$files): void {
            file_put_contents($base . '/' . $rel, $content);
            $files[] = $rel;
        };

        $write('manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $write('src/PingEndpoint.php', $this->endpointStub($namespace));
        $write('migrations/001_init.sql', $this->migrationStub($key));
        $write('README.md', $this->readmeStub($key, $namespace));

        return $files;
    }

    private function endpointStub(string $namespace): string
    {
        return <<<PHP
            <?php
            declare(strict_types=1);

            namespace $namespace;

            use App\\Service\\Api\\ApiEndpointInterface;

            /**
             * Beispiel-API-Endpunkt: GET /api/v1/m/<key>/ping
             */
            class PingEndpoint implements ApiEndpointInterface
            {
                public function handle(array \$request): array
                {
                    return ['status' => 200, 'body' => ['pong' => true, 'user_id' => \$request['user_id'] ?? null]];
                }
            }

            PHP;
    }

    private function migrationStub(string $key): string
    {
        return "-- Modul-Tabelle (läuft im Schema mod_$key; bei is_scoped RLS ergänzen).\n"
            . "CREATE TABLE example (\n"
            . "    id   uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,\n"
            . "    note text\n"
            . ");\n"
            . "-- @DOWN\n"
            . "DROP TABLE example;\n";
    }

    private function readmeStub(string $key, string $namespace): string
    {
        return "# $key\n\nGeneriertes Modul-Gerüst (Namespace `$namespace`).\n\n"
            . "Installation (Dev):\n\n    bin/cake module_lint ./$key/manifest.json\n"
            . "    bin/cake module install ./$key\n\n"
            . "Erweiterungspunkte: `bin/cake module_contracts`.\n";
    }
}
