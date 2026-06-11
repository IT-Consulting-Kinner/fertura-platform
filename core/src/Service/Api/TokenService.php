<?php
declare(strict_types=1);

namespace App\Service\Api;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use Cake\Datasource\ConnectionManager;

/**
 * Verwaltung der API-Tokens für die externe API (Kap. 29 / Entscheidung 162).
 *
 * Ein Token bindet an einen Benutzer; autorisiert wird über dessen Rechte. Die
 * `scopes` schränken zusätzlich ein, welche API-Fähigkeiten das Token nutzen
 * darf (`*` = alle). Der Klartext wird **nur bei der Erzeugung** zurückgegeben;
 * gespeichert wird ausschließlich der SHA-256-Hash.
 */
class TokenService
{
    public const PREFIX = 'ftra_';

    /** Bekannte Scopes (für die GUI; die Prüfung erlaubt auch `*`). */
    public const KNOWN_SCOPES = ['me:read', 'health:read', 'modules:read'];

    public function __construct(private ?AuditLogger $audit = null)
    {
        $this->audit ??= new AuditLogger();
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Erzeugt ein Token. Gibt den **Klartext** (einmalig) + die Zeilen-ID zurück.
     *
     * @param list<string> $scopes
     * @return array{id: string, token: string}
     */
    public function create(string $userId, string $label, array $scopes, ?string $expiresAt, ?string $actorId): array
    {
        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $row = $this->conn()->execute(
            'INSERT INTO api_tokens (user_id, token_hash, label, scopes, expires_at, created_by) '
            . 'VALUES (:u, :h, :l, CAST(:s AS jsonb), :e, :cb) RETURNING id',
            [
                'u' => $userId, 'h' => self::hash($plain), 'l' => $label !== '' ? $label : null,
                's' => json_encode(array_values($scopes)), 'e' => $expiresAt ?: null, 'cb' => $actorId,
            ],
        )->fetch('assoc');
        $id = (string)$row['id'];
        $this->audit->log('api_token.create', 'api_token', $id, [
            'component' => 'core',
            'newValue' => ['label' => $label, 'scopes' => $scopes, 'expires_at' => $expiresAt],
        ]);

        return ['id' => $id, 'token' => $plain];
    }

    /**
     * Authentifiziert einen Klartext-Token. Gibt die Token-/Benutzerdaten zurück
     * oder null (unbekannt/abgelaufen/widerrufen/Benutzer inaktiv). Aktualisiert
     * `last_used_at` bei Erfolg.
     *
     * @return array{token_id:string, user_id:string, username:string, email:?string, locale:?string, scopes:list<string>}|null
     */
    public function authenticate(string $plain): ?array
    {
        if (!str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $row = $this->conn()->execute(
            'SELECT t.id AS token_id, t.scopes, t.expires_at, t.revoked_at, '
            . 'u.id AS user_id, u.username, u.email, u.locale, u.status '
            . 'FROM api_tokens t JOIN users u ON u.id = t.user_id '
            . 'WHERE t.token_hash = :h',
            ['h' => self::hash($plain)],
        )->fetch('assoc');
        if ($row === false) {
            return null;
        }
        if ($row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        if ((string)$row['status'] !== 'active') {
            return null;
        }
        $this->conn()->execute('UPDATE api_tokens SET last_used_at = now() WHERE id = :id', ['id' => $row['token_id']]);

        return [
            'token_id' => (string)$row['token_id'],
            'user_id' => (string)$row['user_id'],
            'username' => (string)$row['username'],
            'email' => $row['email'] !== null ? (string)$row['email'] : null,
            'locale' => $row['locale'] !== null ? (string)$row['locale'] : null,
            'scopes' => $this->decodeScopes($row['scopes']),
        ];
    }

    /**
     * Tokens eines Benutzers (ohne Hash/Klartext).
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, label, scopes, last_used_at, expires_at, revoked_at, created_at '
            . 'FROM api_tokens WHERE user_id = :u ORDER BY created_at DESC',
            ['u' => $userId],
        )->fetchAll('assoc');
        foreach ($rows as &$r) {
            $r['scopes'] = $this->decodeScopes($r['scopes']);
        }

        return $rows;
    }

    /** Widerruft ein Token des Benutzers (idempotent). */
    public function revoke(string $tokenId, string $userId): bool
    {
        // UUID-Guard: die Token-ID kommt aus der URL; fehlgeformte Werte wie
        // unbekannte behandeln statt 22P02 -> 500 (vgl. \App\Infrastructure\Uuid).
        if (!Uuid::isValid($tokenId)) {
            return false;
        }
        $n = $this->conn()->execute(
            'UPDATE api_tokens SET revoked_at = now() WHERE id = :id AND user_id = :u AND revoked_at IS NULL',
            ['id' => $tokenId, 'u' => $userId],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('api_token.revoke', 'api_token', $tokenId, ['component' => 'core']);
        }

        return $n > 0;
    }

    /** Prüft, ob die Scope-Liste den geforderten Scope abdeckt (`*` = alle). */
    public static function hasScope(array $scopes, string $required): bool
    {
        return in_array('*', $scopes, true) || in_array($required, $scopes, true);
    }

    /** @return list<string> */
    private function decodeScopes(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        $d = json_decode((string)$raw, true);

        return is_array($d) ? array_values(array_map('strval', $d)) : [];
    }
}
