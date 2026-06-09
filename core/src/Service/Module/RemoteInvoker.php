<?php
declare(strict_types=1);

namespace App\Service\Module;

use RuntimeException;

/**
 * Core-seitiger Client für Out-of-Process-Module (Kap. 23.16, Phase 1).
 *
 * Ruft einen Service-Contract eines isolierten Modulprozesses über dessen
 * Unix-Domain-Socket auf (JSON-Zeilen-Protokoll). Ein-/Ausgabe sind die bereits
 * serialisierbaren Contract-Arrays (Kap. 29) — daher transparent zum
 * In-Process-Aufruf über {@see \App\Service\Registry\CapabilityHandle::invoke()}.
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
     * Ruft `$contract` des Modulprozesses `$moduleKey` mit `$input` auf.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function invoke(string $moduleKey, string $contract, array $input): array
    {
        $path = $this->socketPath($moduleKey);
        $sock = @stream_socket_client('unix://' . $path, $errno, $errstr, 5);
        if ($sock === false) {
            throw new RuntimeException("Modul-Host nicht erreichbar ($moduleKey): $errstr");
        }
        stream_set_timeout($sock, 30);
        $payload = ['contract' => $contract, 'input' => $input];
        $tokenFile = $this->tokenDir . '/' . $moduleKey . '.token';
        if (is_file($tokenFile)) {
            $payload['token'] = trim((string)file_get_contents($tokenFile));
        }
        fwrite($sock, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n");
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

        return (array)($resp['output'] ?? []);
    }
}
