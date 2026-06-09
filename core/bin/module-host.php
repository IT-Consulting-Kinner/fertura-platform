<?php
declare(strict_types=1);

/**
 * Isolierter Out-of-Process-Modul-Host (Kap. 23.16.2, Phase 3).
 *
 * Läuft als **separater Prozess** mit **bereinigter Umgebung** (kein Core-
 * `DATABASE_URL`/`BACKUP_PASSWORD`) und **eigener, eingeschränkter DB-Rolle**.
 * Lädt nur den Modulcode (sein `php_namespace`) und konfiguriert eine
 * CakePHP-`default`-Connection auf die Modul-Rolle, sodass Modul-Beitragsklassen
 * (Service/Resolver/Collector/Event) wie in-process über `ConnectionManager`
 * arbeiten — aber isoliert. Der Core ruft ausschließlich über den
 * token-gesicherten Unix-Domain-Socket auf ({@see RemoteInvoker}).
 *
 * Protokoll (JSON-Zeilen): {nonce, exp, cap, op, …}
 *   - op='probe'                                  -> Isolationsdiagnose
 *   - op='call', class, method, args[], rls{}     -> $impl->$method(...$args)
 *   - {contract, input}            (Alt-Service)  -> map[contract]->handle(input)
 *
 * Auth: Jede Anfrage trägt ein **Pro-Aufruf-Capability-Token** (nonce/exp/cap).
 * `cap` ist ein HMAC über die kanonisierte Anfrage + Nonce + Ablauf, mit dem
 * gemeinsamen Geheimnis (MODULE_RPC_TOKEN) als Schlüssel — das Geheimnis selbst
 * reist nie über den Socket. Der Host prüft MAC + Ablauf + Einmaligkeit der
 * Nonce ({@see RpcCapabilityToken}).
 *
 * RLS: Der Aufrufer reicht seinen Zeilenkontext (`rls`) mit; der Host setzt ihn
 * je Aufruf transaktionslokal auf der Modul-Connection (set_config).
 *
 * Erwartete Umgebung: MODULE_KEY, MODULE_SOCKET, MODULE_SRC, MODULE_NAMESPACE,
 * MODULE_MANIFEST, MODULE_DB_URL (CakePHP-URL der Modul-Rolle), MODULE_RPC_TOKEN
 * (gemeinsames HMAC-Geheimnis, NICHT mehr als Klartext-Bearer mitgeschickt).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Module\RpcCapabilityToken;
use Cake\Datasource\ConnectionManager;

$key = (string)($argv[1] ?? getenv('MODULE_KEY'));
$socket = (string)getenv('MODULE_SOCKET');
$src = (string)getenv('MODULE_SRC');
$namespace = (string)getenv('MODULE_NAMESPACE');
$manifest = (string)getenv('MODULE_MANIFEST');
$dbUrl = (string)getenv('MODULE_DB_URL');
// HMAC-Geheimnis aus der 0600-Datei (bevorzugt) lesen — der Wert liegt NICHT
// als Klartext in der Prozess-Umgebung/Kommandozeile. Fallback auf den
// direkten Env-Wert nur für Alt-/Test-Spawns.
$secretFile = (string)getenv('MODULE_RPC_TOKEN_FILE');
$rpcToken = $secretFile !== '' && is_file($secretFile)
    ? trim((string)@file_get_contents($secretFile))
    : (string)getenv('MODULE_RPC_TOKEN');

if ($key === '' || $socket === '' || $src === '' || $namespace === '') {
    fwrite(STDERR, "module-host: MODULE_KEY/SOCKET/SRC/NAMESPACE erforderlich\n");
    exit(2);
}
// Fail-closed: ohne Geheimnis NICHT unauthentifiziert bedienen (kein Fail-open).
if ($rpcToken === '') {
    fwrite(STDERR, "module-host[$key]: kein RPC-Geheimnis -> verweigere Start (fail-closed).\n");
    exit(4);
}

// Nur den Modul-Namespace autoloaden (Modul-src). Die Contract-SDK
// (App\Service\...) kommt über den Composer-Autoloader.
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

// Alt-Service-Map (Contract -> Klasse) aus dem Manifest (Abwärtskompatibilität).
$serviceMap = [];
if ($manifest !== '' && is_file($manifest)) {
    $m = json_decode((string)file_get_contents($manifest), true) ?: [];
    foreach ($m['services_registered'] ?? [] as $s) {
        if (isset($s['contract'], $s['class'])) {
            $serviceMap[(string)$s['contract']] = (string)$s['class'];
        }
    }
}

// CakePHP-`default`-Connection ausschließlich über die eingeschränkte Modul-Rolle.
// Search-Path auf das eigene Schema, damit Modulcode unqualifiziert darauf zugreift.
$dbReady = false;
if ($dbUrl !== '') {
    try {
        ConnectionManager::setConfig('default', [
            'url' => $dbUrl,
            'timezone' => 'UTC',
            'quoteIdentifiers' => true,
            'init' => ["SET search_path TO mod_$key, core, public"],
        ]);
        ConnectionManager::get('default')->execute('SELECT 1');
        $dbReady = true;
    } catch (Throwable $e) {
        fwrite(STDERR, "module-host[$key]: DB nicht verbunden (" . $e->getMessage() . ")\n");
    }
}

/** Ruft $class::$method(...$args) im RLS-Kontext $rls auf (transaktionslokal). */
$invoke = static function (string $class, string $method, array $args, array $rls) use ($ns, $dbReady): array {
    if (!str_starts_with($class, $ns)) {
        return ['error' => "Klasse außerhalb des Modul-Namespace: $class"];
    }
    if (!class_exists($class)) {
        return ['error' => "Klasse nicht ladbar: $class"];
    }
    if (!method_exists($class, $method)) {
        return ['error' => "Methode fehlt: $class::$method"];
    }
    if (!$dbReady) {
        // Ohne DB-Connection keine RLS-Transaktion -> direkt aufrufen.
        try {
            return ['output' => (new $class())->$method(...$args)];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
    $conn = ConnectionManager::get('default');
    $conn->begin();
    try {
        // RLS-Zeilenkontext der aufrufenden Anfrage anwenden (Kap. 30.3).
        $conn->execute("SELECT set_config('app.current_user_id', :u, true)", ['u' => (string)($rls['user_id'] ?? '')]);
        $conn->execute("SELECT set_config('app.current_group_ids', :g, true)", ['g' => implode(',', array_map('strval', (array)($rls['group_ids'] ?? [])))]);
        $conn->execute("SELECT set_config('app.bypass_rls', :b, true)", ['b' => !empty($rls['bypass']) ? 'true' : 'false']);
        $out = (new $class())->$method(...$args);
        $conn->commit();

        return ['output' => $out];
    } catch (Throwable $e) {
        $conn->rollback();

        return ['error' => $e->getMessage()];
    }
};

// Bereits eingelöste Nonces (Nonce -> Ablauf) für Einmaligkeit/Replay-Schutz.
$seenNonces = [];

$dispatch = static function (array $req) use ($serviceMap, $key, $rpcToken, $dbReady, $invoke, &$seenNonces): array {
    // Pro-Aufruf-Authentifizierung (Kap. 23.16.2): der MAC (`cap`) muss zur
    // kanonisierten Anfrage + Nonce + Ablauf passen; das Geheimnis selbst reist
    // nie über den Socket. Zusätzlich Replay-Schutz über die einmalige Nonce.
    // Das Geheimnis ist garantiert gesetzt (sonst Fail-closed beim Start).
    {
        if (!RpcCapabilityToken::verify($rpcToken, $req)) {
            return ['error' => 'nicht autorisiert'];
        }
        $now = time();
        foreach ($seenNonces as $n => $exp) {
            if ($exp < $now) {
                unset($seenNonces[$n]); // abgelaufene Nonces verwerfen (begrenzt den Speicher)
            }
        }
        $nonce = (string)($req['nonce'] ?? '');
        if (isset($seenNonces[$nonce])) {
            return ['error' => 'nicht autorisiert']; // Replay derselben Nonce
        }
        $seenNonces[$nonce] = (int)($req['exp'] ?? $now);
    }
    $op = (string)($req['op'] ?? '');

    if ($op === 'probe' || ($req['contract'] ?? '') === '__probe') {
        $tryRead = static function (string $sql) use ($dbReady): ?bool {
            if (!$dbReady) {
                return null;
            }
            try {
                ConnectionManager::get('default')->execute($sql);

                return true;
            } catch (Throwable) {
                return false;
            }
        };

        return ['output' => [
            'module' => $key,
            'sees_core_database_url' => getenv('DATABASE_URL') !== false && getenv('DATABASE_URL') !== '',
            'sees_backup_password' => getenv('BACKUP_PASSWORD') !== false && getenv('BACKUP_PASSWORD') !== '',
            'can_read_core_users' => $tryRead('SELECT count(*) FROM core.users'),
            'can_read_own_schema' => $tryRead('SELECT count(*) FROM mod_' . $key . '.ping_log'),
            'db_connected' => $dbReady,
        ]];
    }

    if ($op === 'call') {
        return $invoke(
            (string)($req['class'] ?? ''),
            (string)($req['method'] ?? ''),
            array_values((array)($req['args'] ?? [])),
            (array)($req['rls'] ?? []),
        );
    }

    // Alt-Pfad: Service-Contract über die Manifest-Map.
    $contract = (string)($req['contract'] ?? '');
    if (!isset($serviceMap[$contract])) {
        return ['error' => "Unbekannter Service-Contract: $contract"];
    }

    return $invoke($serviceMap[$contract], 'handle', [(array)($req['input'] ?? [])], (array)($req['rls'] ?? []));
};

// Binden, OHNE einen evtl. lebenden Vorgänger-Socket blind zu stehlen.
$server = @stream_socket_server('unix://' . $socket, $errno, $errstr);
if ($server === false) {
    $probe = @stream_socket_client('unix://' . $socket, $pe, $ps, 1);
    if ($probe !== false) {
        fclose($probe);
        fwrite(STDERR, "module-host[$key]: bereits ein aktiver Host -> beende.\n");
        exit(0); // lebenden Host nicht verdrängen
    }
    @unlink($socket); // verwaister Socket -> entfernen und erneut binden
    $server = stream_socket_server('unix://' . $socket, $errno, $errstr);
}
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
    stream_set_timeout($conn, 30);
    // Anfragezeile begrenzen (DoS-Schutz vor dem Decodieren): RPC-Nutzlasten
    // sind klein und zeilenterminiert. `fgets` liest höchstens das Limit; fehlt
    // dann der Zeilenabschluss, war die Zeile überlang (oder fehlerhaft ohne
    // \n) -> ablehnen, ohne json_decode mit Riesendaten zu füttern.
    $maxLine = 4 * 1024 * 1024;
    $line = fgets($conn, $maxLine);
    if ($line !== false && !str_ends_with($line, "\n")) {
        $resp = ['error' => 'Anfrage zu groß oder unvollständig'];
        fwrite($conn, json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
        fclose($conn);
        continue;
    }
    if ($line !== false) {
        $req = json_decode(trim($line), true);
        $resp = is_array($req) ? $dispatch($req) : ['error' => 'ungültige Anfrage'];
        fwrite($conn, json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
    }
    fclose($conn);
}
@unlink($socket);
