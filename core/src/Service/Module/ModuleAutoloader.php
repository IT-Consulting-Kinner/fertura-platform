<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * PSR-4-Autoloader für installierte Module (Step 7, "echtes Laden").
 *
 * Registriert einen eigenen spl_autoload_register-Handler (unabhängig von der
 * Composer-Loader-Instanz), der die Namespaces aktiver Module auf ihre src/-
 * Pfade abbildet. Dadurch werden deklarierte Listener/Resolver/Service-Klassen
 * real geladen.
 */
class ModuleAutoloader
{
    /** @var array<string, string> namespace-prefix => src-Pfad */
    private static array $prefixes = [];

    private static bool $registered = false;

    public static function register(string $namespace, string $srcPath): void
    {
        $prefix = rtrim($namespace, '\\') . '\\';
        self::$prefixes[$prefix] = $srcPath;

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load']);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $src) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = rtrim($src, '/') . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }

    /**
     * Registriert die Autoload-Pfade aller AKTIVEN Module. Wird im
     * Application-Bootstrap und vom Worker aufgerufen (fehlertolerant).
     */
    public static function registerActiveModules(): void
    {
        try {
            $rows = ConnectionManager::get('default')->execute(
                "SELECT php_namespace, source_path FROM modules "
                . "WHERE status = 'active' AND php_namespace IS NOT NULL AND source_path IS NOT NULL",
            )->fetchAll('assoc');
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            self::register((string)$row['php_namespace'], rtrim((string)$row['source_path'], '/') . '/src');
        }
    }
}
