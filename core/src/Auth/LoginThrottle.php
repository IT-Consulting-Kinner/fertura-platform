<?php
declare(strict_types=1);

namespace App\Auth;

use App\Service\Settings\SettingsManager;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Login protection: server-side rate limiting / temporary lockout on repeated
 * failed login/token attempts (Decision 162 / ch. 27.16.3).
 *
 * Safe defaults apply even without configuration. From Step 4 (configuration
 * store) onward the thresholds become overridable from the DB.
 *
 * Persistence: core.auth_failures (identifier, ip_address, occurred_at).
 */
class LoginThrottle
{
    /** Safe defaults (Decision 162). */
    public const DEFAULT_MAX_ATTEMPTS = 10;
    public const DEFAULT_WINDOW_MINUTES = 15;
    /** Per-IP cap: higher than per user (shared NAT/office IPs), but bounds
     *  password spraying across many usernames and the pre-auth CPU cost. */
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
        // Thresholds from the configuration store (DB), code constants as a safety net.
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
     * Records a failed attempt.
     */
    public function recordFailure(string $identifier, ?string $ip = null): void
    {
        $this->connection()->execute(
            'INSERT INTO auth_failures (identifier, ip_address) VALUES (:id, :ip)',
            ['id' => $identifier, 'ip' => $ip],
        );
    }

    /**
     * Number of failed attempts within the time window.
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
     * Is the identifier currently blocked (threshold reached)?
     */
    public function isBlocked(string $identifier): bool
    {
        return $this->recentFailures($identifier) >= $this->maxAttempts;
    }

    /**
     * Number of failed attempts from **this IP** (across arbitrary usernames)
     * within the time window — catches password spraying.
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

    /** Is the IP currently blocked (too many failed attempts across all accounts)? */
    public function isIpBlocked(string $ip): bool
    {
        return $ip !== '' && $this->recentIpFailures($ip) >= $this->ipMaxAttempts;
    }

    public function ipMaxAttempts(): int
    {
        return $this->ipMaxAttempts;
    }

    /**
     * Resets the counter after a successful login.
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
