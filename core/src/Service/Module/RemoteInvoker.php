<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Core-seitiger Client für Out-of-Process-Module (Kap. 23.16.2, Phase 3).
 *
 * Ruft einen Erweiterungspunkt eines isolierten Modulprozesses über dessen
 * token-gesicherten Unix-Domain-Socket auf (JSON-Zeilen). Reicht den aktuellen
 * RLS-Zeilenkontext der Anfrage mit, damit die Modul-Beiträge im Host
 * gruppen-/benutzer-scoped arbeiten (Kap. 30.3).
 *   - {@see invoke()} Service-Contract (Alt-Pfad, transparent zu CapabilityHandle)
 *   - {@see call()}   beliebiger Beitrag: $class::$method(...$args)
 */
class RemoteInvoker
{
    private string $socketDir;
    private string $tokenDir;

    public function __construct(?string $socketDir = null)
    {
        $this->socketDir = rtrim($socketDir ?? (sys_get_temp_dir() . '/fertura-mod'), '/');
        // Token-Verzeichnis liegt parallel zum Socket-Verzeichnis (s. Supervisor).
        $this->tokenDir = dirname($this->socketDir) . '/fertura-mod-tokens';
    }

    public function socketPath(string $moduleKey): string
    {
        return $this->socketDir . '/' . $moduleKey . '.sock';
    }

    public function isRunning(string $moduleKey): bool
    {
        $sock = @stream_socket_client('unix://' . $this->socketPath($moduleKey), $errno, $errstr, 1);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    /**
     * Ruft den Service-Contract `$contract` mit `$input` auf (Alt-Pfad).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function invoke(string $moduleKey, string $contract, array $input): array
    {
        $req = ['contract' => $contract, 'input' => $input, 'rls' => $this->currentRls()];

        return (array)$this->send($moduleKey, $req);
    }

    /**
     * Ruft einen beliebigen Beitrag des Moduls auf: `$class::$method(...$args)`,
     * im aktuellen (oder übergebenen) RLS-Kontext. Rückgabe ist die rohe Ausgabe
     * der Methode (Array, Skalar oder null bei void).
     *
     * @param list<mixed> $args
     * @param array{user_id?:?string,group_ids?:list<string>,bypass?:bool}|null $rls
     */
    public function call(string $moduleKey, string $class, string $method, array $args, ?array $rls = null): mixed
    {
        $req = [
            'op' => 'call',
            'class' => $class,
            'method' => $method,
            'args' => array_values($args),
            'rls' => $rls ?? $this->currentRls(),
        ];

        return $this->send($moduleKey, $req);
    }

    /** Sendet eine Anfrage und liefert die `output`-Nutzlast (wirft bei Fehler). */
    private function send(string $moduleKey, array $req): mixed
    {
        $sock = @stream_socket_client('unix://' . $this->socketPath($moduleKey), $errno, $errstr, 5);
        if ($sock === false) {
            throw new RuntimeException("Modul-Host nicht erreichbar ($moduleKey): $errstr");
        }
        stream_set_timeout($sock, 30);
        $tokenFile = $this->tokenDir . '/' . $moduleKey . '.token';
        if (is_file($tokenFile)) {
            $req['token'] = trim((string)file_get_contents($tokenFile));
        }
        fwrite($sock, json_encode($req, JSON_UNESCAPED_UNICODE) . "\n");
        $line = fgets($sock);
        fclose($sock);

        if ($line === false) {
            throw new RuntimeException("Keine Antwort vom Modul-Host ($moduleKey).");
        }
        $resp = json_decode(trim($line), true);
        if (!is_array($resp)) {
            throw new RuntimeException("Ungültige Antwort vom Modul-Host ($moduleKey).");
        }
        if (isset($resp['error'])) {
            throw new RuntimeException('Modul-Aufruf abgewiesen: ' . (string)$resp['error']);
        }

        return $resp['output'] ?? null;
    }

    /**
     * Liest den aktuell gesetzten RLS-Zeilenkontext der Default-Connection
     * (von der TransactionRlsMiddleware bzw. dem aufrufenden Code gesetzt), um
     * ihn an den isolierten Host weiterzureichen.
     *
     * @return array{user_id:?string,group_ids:list<string>,bypass:bool}
     */
    private function currentRls(): array
    {
        try {
            $row = ConnectionManager::get('default')->execute(
                "SELECT nullif(current_setting('app.current_user_id', true), '') AS uid, "
                . "current_setting('app.current_group_ids', true) AS gids, "
                . "coalesce(nullif(current_setting('app.bypass_rls', true), ''), 'false') AS bypass",
            )->fetch('assoc');
        } catch (Throwable) {
            return ['user_id' => null, 'group_ids' => [], 'bypass' => false];
        }
        $gids = (string)($row['gids'] ?? '');

        return [
            'user_id' => $row['uid'] !== null && $row['uid'] !== '' ? (string)$row['uid'] : null,
            'group_ids' => $gids === '' ? [] : explode(',', $gids),
            'bypass' => (string)($row['bypass'] ?? 'false') === 'true',
        ];
    }
}
