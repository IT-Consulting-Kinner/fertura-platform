<?php
declare(strict_types=1);

namespace App\Auth;

use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Anmeldeschutz: serverseitiges Rate-Limiting / temporäre Sperre bei wiederholt
 * fehlgeschlagenen Anmelde-/Token-Versuchen (Entscheidung 162 / Kap. 27.16.3).
 *
 * Sichere Vorgabewerte greifen auch ohne Konfiguration. Die Schwellenwerte
 * werden ab Step 4 (Konfigurationsspeicher) aus der DB überschreibbar.
 *
 * Persistenz: core.auth_failures (identifier, ip_address, occurred_at).
 */
class LoginThrottle
{
    /** Sichere Defaults (Entscheidung 162). */
    public const DEFAULT_MAX_ATTEMPTS = 10;
    public const DEFAULT_WINDOW_MINUTES = 15;
    /** Per-IP-Obergrenze: höher als pro Benutzer (geteilte NAT/Office-IPs), aber
     *  begrenzt Password-Spraying über viele Benutzernamen und die Pre-Auth-CPU. */
    public const DEFAULT_IP_MAX_ATTEMPTS = 30;

    private int $maxAttempts;
    private int $windowMinutes;
    private int $ipMaxAttempts;

    public function __construct(
        ?int $maxAttempts = null,
        ?int $windowMinutes = null,
        ?SettingsManager $settings = null,
        ?int $ipMaxAttempts = null,
    ) {
        // Schwellen aus dem Konfigurationsspeicher (DB), Code-Konstanten als Netz.
        $settings ??= new SettingsManager();
        $this->maxAttempts = $maxAttempts
            ?? (int)$settings->get('core', 'login_throttle.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        $this->windowMinutes = $windowMinutes
            ?? (int)$settings->get('core', 'login_throttle.window_minutes', self::DEFAULT_WINDOW_MINUTES);
        $this->ipMaxAttempts = $ipMaxAttempts
            ?? (int)$settings->get('core', 'login_throttle.ip_max_attempts', self::DEFAULT_IP_MAX_ATTEMPTS);
    }

    private function connection(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    /**
     * Protokolliert einen fehlgeschlagenen Versuch.
     */
    public function recordFailure(string $identifier, ?string $ip = null): void
    {
        $this->connection()->execute(
            'INSERT INTO auth_failures (identifier, ip_address) VALUES (:id, :ip)',
            ['id' => $identifier, 'ip' => $ip],
        );
    }

    /**
     * Anzahl der Fehlversuche innerhalb des Zeitfensters.
     */
    public function recentFailures(string $identifier): int
    {
        $row = $this->connection()->execute(
            'SELECT count(*) AS c FROM auth_failures ' .
            "WHERE lower(identifier) = lower(:id) AND occurred_at > now() - (:mins || ' minutes')::interval",
            ['id' => $identifier, 'mins' => (string)$this->windowMinutes],
        )->fetch('assoc');

        return (int)($row['c'] ?? 0);
    }

    /**
     * Ist die Kennung aktuell gesperrt (Schwellwert erreicht)?
     */
    public function isBlocked(string $identifier): bool
    {
        return $this->recentFailures($identifier) >= $this->maxAttempts;
    }

    /**
     * Anzahl der Fehlversuche **dieser IP** (über beliebige Benutzernamen)
     * innerhalb des Zeitfensters — fängt Password-Spraying ab.
     */
    public function recentIpFailures(string $ip): int
    {
        if ($ip === '') {
            return 0;
        }
        $row = $this->connection()->execute(
            'SELECT count(*) AS c FROM auth_failures ' .
            "WHERE ip_address = :ip AND occurred_at > now() - (:mins || ' minutes')::interval",
            ['ip' => $ip, 'mins' => (string)$this->windowMinutes],
        )->fetch('assoc');

        return (int)($row['c'] ?? 0);
    }

    /** Ist die IP aktuell gesperrt (zu viele Fehlversuche über alle Konten)? */
    public function isIpBlocked(string $ip): bool
    {
        return $ip !== '' && $this->recentIpFailures($ip) >= $this->ipMaxAttempts;
    }

    public function ipMaxAttempts(): int
    {
        return $this->ipMaxAttempts;
    }

    /**
     * Setzt den Zähler nach erfolgreicher Anmeldung zurück.
     */
    public function clear(string $identifier): void
    {
        $this->connection()->execute(
            'DELETE FROM auth_failures WHERE lower(identifier) = lower(:id)',
            ['id' => $identifier],
        );
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function windowMinutes(): int
    {
        return $this->windowMinutes;
    }
}
