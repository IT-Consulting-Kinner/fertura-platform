<?php
declare(strict_types=1);

namespace App\Service\Module;

use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Core-side client for out-of-process modules (ch. 23.16.2, phase 3).
 *
 * Invokes an extension point of an isolated module process over its
 * token-secured Unix domain socket (JSON lines). Passes the request's current
 * RLS row context along, so the module contributions in the host work
 * group/user-scoped (ch. 30.3).
 *   - {@see invoke()} service contract (legacy path, transparent to CapabilityHandle)
 *   - {@see call()}   arbitrary contribution: $class::$method(...$args)
 */
class RemoteInvoker
{
    private string $socketDir;
    private string $tokenDir;

    public function __construct(?string $socketDir = null)
    {
        $this->socketDir = rtrim($socketDir ?? (sys_get_temp_dir() . '/fertura-mod'), '/');
        // The token directory sits alongside the socket directory (see the supervisor).
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
     * Invokes the service contract `$contract` with `$input` (legacy path).
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
     * Invokes an arbitrary contribution of the module: `$class::$method(...$args)`,
     * in the current (or supplied) RLS context. Returns the method's raw output
     * (array, scalar, or null for void).
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

    /** Sends a request and returns the `output` payload (throws on error). */
    private function send(string $moduleKey, array $req): mixed
    {
        $sock = @stream_socket_client('unix://' . $this->socketPath($moduleKey), $errno, $errstr, 5);
        if ($sock === false) {
            throw new RuntimeException("Modul-Host nicht erreichbar ($moduleKey): $errstr");
        }
        stream_set_timeout($sock, 30);
        // Per-call capability token: the shared secret serves only as an HMAC key
        // and does NOT travel over the socket; what is sent is a call-bound MAC +
        // nonce + expiry (ch. 23.16.2).
        $secretFile = $this->tokenDir . '/' . $moduleKey . '.token';
        if (is_file($secretFile)) {
            $secret = trim((string)file_get_contents($secretFile));
            if ($secret !== '') {
                $req += RpcCapabilityToken::mint($secret, $req);
            }
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
     * Reads the currently set RLS row context of the default connection (set by
     * the TransactionRlsMiddleware or the calling code), in order to pass it on
     * to the isolated host.
     *
     * @return array{user_id:?string,group_ids:list<string>,bypass:bool,tenant_id:?string}
     */
    private function currentRls(): array
    {
        try {
            $row = ConnectionManager::get('default')->execute(
                "SELECT nullif(current_setting('app.current_user_id', true), '') AS uid, "
                . "current_setting('app.current_group_ids', true) AS gids, "
                . "nullif(current_setting('app.current_tenant_id', true), '') AS tid, "
                . "coalesce(nullif(current_setting('app.bypass_rls', true), ''), 'false') AS bypass",
            )->fetch('assoc');
        } catch (Throwable) {
            return ['user_id' => null, 'group_ids' => [], 'bypass' => false, 'tenant_id' => null];
        }
        $gids = (string)($row['gids'] ?? '');

        return [
            'user_id' => $row['uid'] !== null && $row['uid'] !== '' ? (string)$row['uid'] : null,
            'group_ids' => $gids === '' ? [] : explode(',', $gids),
            'bypass' => (string)($row['bypass'] ?? 'false') === 'true',
            // Pass the tenant context along, so tenant-scoped data is also scoped
            // correctly in the isolated module host (otherwise fail-closed).
            'tenant_id' => $row['tid'] !== null && $row['tid'] !== '' ? (string)$row['tid'] : null,
        ];
    }
}
