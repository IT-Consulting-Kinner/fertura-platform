<?php
declare(strict_types=1);

/**
 * Isolierter Out-of-Process-Modul-Host (Kap. 23.16, Phase 1).
 *
 * Läuft als **separater Prozess** mit **bereinigter Umgebung** (kein Core-
 * `DATABASE_URL`/`BACKUP_PASSWORD`) und **eigener, eingeschränkter DB-Rolle**
 * (`MODULE_DB_URL`). Lädt nur den Modulcode (sein `php_namespace`) und stellt
 * dessen Service-Contracts über einen Unix-Domain-Socket (JSON-Zeilen) bereit.
 * Der Core ruft ausschließlich über diesen Socket auf ({@see RemoteInvoker}).
 *
 * Erwartete Umgebung: MODULE_KEY, MODULE_SOCKET, MODULE_SRC, MODULE_NAMESPACE,
 * MODULE_MANIFEST, MODULE_DB_URL (PDO-DSN der Modul-Rolle, optional).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$key = (string)($argv[1] ?? getenv('MODULE_KEY'));
$socket = (string)getenv('MODULE_SOCKET');
$src = (string)getenv('MODULE_SRC');
$namespace = (string)getenv('MODULE_NAMESPACE');
$manifest = (string)getenv('MODULE_MANIFEST');
$dbUrl = (string)getenv('MODULE_DB_URL');

if ($key === '' || $socket === '' || $src === '' || $namespace === '') {
    fwrite(STDERR, "module-host: MODULE_KEY/SOCKET/SRC/NAMESPACE erforderlich\n");
    exit(2);
}

// Nur den Modul-Namespace autoloaden (Modul-src). Die Contract-SDK
// (App\Service\Registry\ServiceInterface) kommt über den Composer-Autoloader.
$ns = rtrim($namespace, '\\') . '\\';
$baseDir = rtrim($src, '/') . '/';
spl_autoload_register(static function (string $class) use ($ns, $baseDir): void {
    if (str_starts_with($class, $ns)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Contract -> Implementierungsklasse aus dem Manifest (services_registered).
$map = [];
if ($manifest !== '' && is_file($manifest)) {
    $m = json_decode((string)file_get_contents($manifest), true) ?: [];
    foreach ($m['services_registered'] ?? [] as $s) {
        if (isset($s['contract'], $s['class'])) {
            $map[(string)$s['contract']] = (string)$s['class'];
        }
    }
}

// DB ausschließlich über die eingeschränkte Modul-Rolle.
$pdo = null;
$pdoErr = null;
if ($dbUrl !== '') {
    try {
        $pdo = new PDO($dbUrl);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        $pdoErr = $e->getMessage();
    }
}

$dispatch = static function (array $req) use ($map, $pdo, $pdoErr, $key): array {
    $contract = (string)($req['contract'] ?? '');
    $input = (array)($req['input'] ?? []);

    if ($contract === '__probe') {
        $tryRead = static function (?PDO $pdo, string $sql): ?bool {
            if ($pdo === null) {
                return null;
            }
            try {
                $pdo->query($sql);

                return true;
            } catch (Throwable) {
                return false;
            }
        };

        return ['output' => [
            'module' => $key,
            'sees_core_database_url' => getenv('DATABASE_URL') !== false && getenv('DATABASE_URL') !== '',
            'sees_backup_password' => getenv('BACKUP_PASSWORD') !== false && getenv('BACKUP_PASSWORD') !== '',
            'can_read_core_users' => $tryRead($pdo, 'SELECT count(*) FROM core.users'),
            'can_read_own_schema' => $tryRead($pdo, 'SELECT count(*) FROM mod_' . $key . '.ping_log'),
            'db_error' => $pdoErr,
        ]];
    }

    if (!isset($map[$contract])) {
        return ['error' => "Unbekannter Service-Contract: $contract"];
    }
    $class = $map[$contract];
    if (!class_exists($class)) {
        return ['error' => "Anbieterklasse nicht ladbar: $class"];
    }
    $impl = new $class();
    if (!$impl instanceof \App\Service\Registry\ServiceInterface) {
        return ['error' => "Kein ServiceInterface: $class"];
    }
    try {
        return ['output' => $impl->handle($input)];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
};

@unlink($socket);
$server = stream_socket_server('unix://' . $socket, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "module-host: Socket nicht bindbar ($errstr)\n");
    exit(3);
}
@chmod($socket, 0o660);
fwrite(STDERR, "module-host[$key] bereit auf $socket\n");

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    $conn = @stream_socket_accept($server, 1);
    if ($conn === false) {
        continue; // Timeout -> Signal-Check
    }
    $line = fgets($conn);
    if ($line !== false) {
        $req = json_decode(trim($line), true);
        $resp = is_array($req) ? $dispatch($req) : ['error' => 'ungültige Anfrage'];
        fwrite($conn, json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
    }
    fclose($conn);
}
@unlink($socket);
