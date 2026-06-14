<?php
declare(strict_types=1);

namespace App\Service\Auth\Sso;

use App\Audit\AuditLogger;
use App\Service\Settings\SecretCipher;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * SSO identity federation (program tier-1, P06): management of identity
 * providers (OIDC/SAML) and **just-in-time provisioning/account linking**.
 *
 * Identities and authorization stay core-managed (ch. 27.2.1): an external
 * provider only authenticates; this service maps the external identity to a
 * core user (via `identity_links` or the email) or creates one (status
 * `active`, without a password). Local login remains possible in parallel
 * (break-glass).
 */
class SsoService
{
    /** Stable id of the default tenant (single-org), mirrors `CoreTenancy`. */
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private ?SecretCipher $cipher = null;

    public function __construct(private ?AuditLogger $audit = null)
    {
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    private function cipher(): SecretCipher
    {
        return $this->cipher ??= new SecretCipher();
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    /**
     * Active providers for the login selection (without secrets).
     *
     * @return list<array{id:string,type:string,name:string,button_label:string}>
     */
    public function activeProviders(): array
    {
        // SSO config is tenant-owned, but sso_providers is a PRE-AUTH table (the
        // login page reads it before any user context) -> no blocking RLS policy;
        // tenant isolation is an application filter instead. The login page resolves
        // its tenant from the host (TransactionRlsMiddleware, pre-auth path); in
        // single-org the host maps to no tenant -> fall back to the default tenant.
        return $this->conn()->execute(
            'SELECT id, type, name, button_label FROM sso_providers '
            . "WHERE active AND tenant_id = coalesce(core.current_tenant(), '"
            . self::DEFAULT_TENANT_ID . "'::uuid) ORDER BY name",
        )->fetchAll('assoc');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProviders(): array
    {
        // Admin listing -> scoped to the current tenant (same pre-auth-safe app
        // filter as activeProviders(); an authenticated admin always has a tenant).
        return $this->conn()->execute(
            'SELECT id, type, name, button_label, active, config, created_at FROM sso_providers '
            . "WHERE tenant_id = coalesce(core.current_tenant(), '"
            . self::DEFAULT_TENANT_ID . "'::uuid) ORDER BY created_at",
        )->fetchAll('assoc');
    }

    /**
     * Full provider including decoded config + decrypted secret.
     *
     * @return array<string,mixed>|null
     */
    public function provider(string $id): ?array
    {
        // `id` is a uuid column: a non-UUID value (e.g. from a route parameter,
        // the SAML RelayState or the session) would otherwise trigger a Postgres
        // `QueryException` (SQLSTATE 22P02) that ends as a 500 and leaks
        // SQL/stack trace into the log. An unknown ID == an unknown provider.
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->conn()->execute(
            'SELECT id, type, name, button_label, active, config, secret_encrypted FROM sso_providers WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');
        if ($row === false) {
            return null;
        }
        $row['config'] = json_decode((string)$row['config'], true) ?: [];
        $row['secret'] = $row['secret_encrypted'] !== null ? $this->cipher()->decrypt((string)$row['secret_encrypted']) : null;
        unset($row['secret_encrypted']);

        return $row;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{id:string}
     */
    public function createProvider(string $type, string $name, array $config, ?string $secret, string $buttonLabel): array
    {
        if (!in_array($type, ['oidc', 'saml'], true)) {
            throw new RuntimeException("Unbekannter SSO-Typ: $type");
        }
        $row = $this->conn()->execute(
            'INSERT INTO sso_providers (type, name, button_label, config, secret_encrypted) '
            . 'VALUES (:t, :n, :b, :c, :s) RETURNING id',
            [
                't' => $type,
                'n' => $name,
                'b' => $buttonLabel,
                'c' => json_encode($config),
                's' => $secret !== null && $secret !== '' ? $this->cipher()->encrypt($secret) : null,
            ],
        )->fetch('assoc');
        $this->audit()->log('sso.provider.create', 'sso_provider', (string)$row['id'], ['type' => $type, 'name' => $name]);

        return ['id' => (string)$row['id']];
    }

    public function setActive(string $id, bool $active): void
    {
        // UUID guard: the ID comes from the admin GUI URL; treat malformed
        // values like unknown ones instead of 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
        if (!\App\Infrastructure\Uuid::isValid($id)) {
            return;
        }
        $this->conn()->execute(
            'UPDATE sso_providers SET active = :a WHERE id = :id',
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        );
    }

    public function deleteProvider(string $id): void
    {
        if (!\App\Infrastructure\Uuid::isValid($id)) {
            return;
        }
        $this->conn()->execute('DELETE FROM sso_providers WHERE id = :id', ['id' => $id]);
        $this->audit()->log('sso.provider.delete', 'sso_provider', $id, []);
    }

    /**
     * Maps an external identity to a core user (link → email → provisioning) and
     * returns the user ID. The caller establishes the session.
     */
    public function loginExternalUser(string $providerId, string $subject, string $email, ?string $first, ?string $last, ?bool $emailVerified = null): string
    {
        $conn = $this->conn();
        $link = $conn->execute(
            'SELECT user_id FROM identity_links WHERE provider_id = :p AND subject = :s',
            ['p' => $providerId, 's' => $subject],
        )->fetch('assoc');
        if ($link !== false) {
            return $this->assertUsable((string)$link['user_id']);
        }

        $email = trim($email);
        if ($email === '') {
            throw new RuntimeException('SSO-Antwort ohne E-Mail — keine Zuordnung möglich.');
        }
        // If the provider explicitly reports the email as unverified (OIDC
        // email_verified=false), never map via it (spoofing protection).
        if ($emailVerified === false) {
            throw new RuntimeException('SSO-Antwort mit unverifizierter E-Mail — Zuordnung abgelehnt.');
        }
        $user = $conn->execute(
            'SELECT id, status, password_hash FROM users WHERE lower(email) = lower(:e)',
            ['e' => $email],
        )->fetch('assoc');

        if ($user === false) {
            $id = $conn->execute(
                'INSERT INTO users (username, email, first_name, last_name, status, password_hash) '
                . "VALUES (:u, :e, :f, :l, 'active', NULL) RETURNING id",
                ['u' => $this->uniqueUsername($email), 'e' => $email, 'f' => $first, 'l' => $last],
            )->fetch('assoc')['id'];
            $this->audit()->log('sso.provision', 'user', (string)$id, ['email' => $email, 'provider_id' => $providerId]);
            $userId = (string)$id;
        } else {
            if (in_array($user['status'], ['disabled', 'anonymized'], true)) {
                throw new RuntimeException('Konto ist deaktiviert.');
            }
            // SECURITY (account-takeover protection): never automatically log
            // into an existing account with a **local password** just because a
            // (possibly foreign) IdP claims its email. Only passwordless accounts
            // (SSO/invited) may be linked by email; local accounts link the IdP
            // explicitly after login.
            if ($user['password_hash'] !== null) {
                throw new RuntimeException(
                    'Ein Konto mit dieser E-Mail und lokalem Login existiert bereits — '
                    . 'bitte zuerst lokal anmelden und SSO in den Kontoeinstellungen verknüpfen.',
                );
            }
            // Account-takeover protection for **open invitations**: a passwordless
            // account in status `invited` (often freshly invited, possibly
            // privileged) must NOT be claimed solely via a not-explicitly-verified
            // IdP email (SAML reports `email_verified`=null). Otherwise an IdP that
            // claims a foreign email could hijack a pending invitation. Verified
            // OIDC emails (email_verified=true) are allowed.
            if ($user['status'] === 'invited' && $emailVerified !== true) {
                throw new RuntimeException(
                    'Zu dieser E-Mail existiert eine offene Einladung — bitte zuerst die '
                    . 'Einladung abschließen, bevor eine SSO-Verknüpfung möglich ist.',
                );
            }
            $userId = (string)$user['id'];
        }

        $conn->execute(
            'INSERT INTO identity_links (user_id, provider_id, subject) VALUES (:u, :p, :s) '
            . 'ON CONFLICT (provider_id, subject) DO NOTHING',
            ['u' => $userId, 'p' => $providerId, 's' => $subject],
        );
        $this->audit()->log('sso.login', 'user', $userId, ['provider_id' => $providerId]);

        return $userId;
    }

    /**
     * Remembers a pending SAML AuthnRequest server-side and binds the (random)
     * `RelayState` to provider + request ID. Cookie-independent replay
     * protection: the IdP mirrors the RelayState back, and at the ACS it is
     * redeemed **once**. Opportunistically cleans up expired entries.
     */
    public function rememberSamlRequest(
        string $relayState,
        string $providerId,
        string $requestId,
        int $ttlSeconds = 600,
    ): void {
        $conn = $this->conn();
        $conn->execute('DELETE FROM saml_auth_requests WHERE expires_at < now()');
        $conn->execute(
            'INSERT INTO saml_auth_requests (relay_state, provider_id, request_id, expires_at) '
            . 'VALUES (:rs, :p, :r, now() + make_interval(secs => :ttl))',
            ['rs' => $relayState, 'p' => $providerId, 'r' => $requestId, 'ttl' => $ttlSeconds],
        );
    }

    /**
     * Redeems a `RelayState` **once** (atomically via `DELETE ... RETURNING`) and
     * returns the bound provider ID + expected request ID — or null if the
     * RelayState is unknown, expired or already consumed (replay). The ACS binds
     * the SAML response to exactly this request ID.
     *
     * @return array{provider_id:string, request_id:string}|null
     */
    public function consumeSamlRequest(string $relayState): ?array
    {
        if ($relayState === '') {
            return null;
        }
        $row = $this->conn()->execute(
            'DELETE FROM saml_auth_requests WHERE relay_state = :rs AND expires_at > now() '
            . 'RETURNING provider_id, request_id',
            ['rs' => $relayState],
        )->fetch('assoc');

        return $row === false ? null : ['provider_id' => (string)$row['provider_id'], 'request_id' => (string)$row['request_id']];
    }

    private function assertUsable(string $userId): string
    {
        $row = $this->conn()->execute('SELECT status FROM users WHERE id = :id', ['id' => $userId])->fetch('assoc');
        if ($row === false || in_array($row['status'], ['disabled', 'anonymized'], true)) {
            throw new RuntimeException('Konto ist deaktiviert.');
        }

        return $userId;
    }

    private function uniqueUsername(string $email): string
    {
        $base = strtolower((string)preg_replace('/[^a-z0-9._-]/i', '', explode('@', $email)[0])) ?: 'user';
        $candidate = $base;
        $i = 1;
        while (
            $this->conn()->execute(
                'SELECT 1 FROM users WHERE lower(username) = lower(:u)',
                ['u' => $candidate],
            )->fetch() !== false
        ) {
            $candidate = $base . ++$i;
        }

        return $candidate;
    }
}
