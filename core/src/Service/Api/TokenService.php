<?php
declare(strict_types=1);

namespace App\Service\Api;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Management of API tokens for the external API (ch. 29 / Decision 162).
 *
 * A token is bound to a user; authorization is derived from that user's
 * permissions. The `scopes` additionally restrict which API capabilities the
 * token may use (`*` = all). The plaintext is returned **only on creation**;
 * only the SHA-256 hash is stored.
 */
class TokenService
{
    public const PREFIX = 'ftra_';

    /**
     * Known scopes offered in the GUI when minting a token (the check in
     * {@see hasScope()} also accepts the `*` wildcard). Must list every scope
     * that an endpoint actually enforces, otherwise a least-privilege token for
     * that endpoint could only be granted via `*`. Kept in sync with the
     * `requireScope()` calls in src/Controller/Api.
     */
    public const KNOWN_SCOPES = ['me:read', 'health:read', 'modules:read', 'audit:read', 'scim:manage'];

    public function __construct(private ?AuditLogger $audit = null)
    {
        $this->audit ??= new AuditLogger();
    }

    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Creates a token. Returns the **plaintext** (once) + the row ID.
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
     * Authenticates a plaintext token. Returns the token/user data, or null
     * (unknown/expired/revoked/user inactive). Updates `last_used_at` on success.
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
     * A user's tokens (without hash/plaintext).
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

        return array_values($rows);
    }

    /** Revokes one of the user's tokens (idempotent). */
    public function revoke(string $tokenId, string $userId): bool
    {
        // UUID guard: the token ID comes from the URL; treat malformed values like
        // unknown ones instead of letting 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
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

    /** Checks whether the scope list covers the required scope (`*` = all). */
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
