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
 * SSO-Identitätsföderation (Programm Tier-1, P06): Verwaltung der Identity
 * Provider (OIDC/SAML) und **Just-in-Time-Provisioning/Account-Linking**.
 *
 * Identitäten und Autorisierung bleiben Core-verwaltet (Kap. 27.2.1): ein
 * externer Provider authentifiziert nur; dieser Dienst ordnet die externe
 * Identität einem Core-Benutzer zu (über `identity_links` oder die E-Mail) bzw.
 * legt ihn an (Status `active`, ohne Passwort). Lokale Anmeldung bleibt parallel
 * möglich (Break-Glass).
 */
class SsoService
{
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
     * Aktive Provider für die Login-Auswahl (ohne Geheimnisse).
     *
     * @return list<array{id:string,type:string,name:string,button_label:string}>
     */
    public function activeProviders(): array
    {
        return $this->conn()->execute(
            'SELECT id, type, name, button_label FROM sso_providers WHERE active ORDER BY name',
        )->fetchAll('assoc');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listProviders(): array
    {
        return $this->conn()->execute(
            'SELECT id, type, name, button_label, active, config, created_at FROM sso_providers ORDER BY created_at',
        )->fetchAll('assoc');
    }

    /**
     * Voller Provider inkl. dekodierter Konfig + entschlüsseltem Geheimnis.
     *
     * @return array<string,mixed>|null
     */
    public function provider(string $id): ?array
    {
        // `id` ist eine uuid-Spalte: ein nicht-UUID-Wert (z. B. aus einem
        // Route-Parameter, der SAML-RelayState oder der Session) löst sonst eine
        // Postgres-`QueryException` (SQLSTATE 22P02) aus, die als 500 endet und
        // SQL/Stacktrace ins Log streut. Unbekannte ID == unbekannter Provider.
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
        $this->conn()->execute(
            'UPDATE sso_providers SET active = :a WHERE id = :id',
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        );
    }

    public function deleteProvider(string $id): void
    {
        $this->conn()->execute('DELETE FROM sso_providers WHERE id = :id', ['id' => $id]);
        $this->audit()->log('sso.provider.delete', 'sso_provider', $id, []);
    }

    /**
     * Ordnet eine externe Identität einem Core-Benutzer zu (Link → E-Mail →
     * Provisioning) und gibt die Benutzer-ID zurück. Der Aufrufer etabliert die
     * Session.
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
        // Sagt der Provider die E-Mail ausdrücklich als unverifiziert (OIDC
        // email_verified=false), niemals darüber zuordnen (Spoofing-Schutz).
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
            // SICHERHEIT (Account-Takeover-Schutz): niemals automatisch in ein
            // bestehendes Konto mit **lokalem Passwort** einloggen, nur weil ein
            // (möglicherweise fremder) IdP dessen E-Mail behauptet. Nur
            // passwortlose Konten (SSO-/eingeladen) dürfen per E-Mail verknüpft
            // werden; lokale Konten verknüpfen den IdP explizit nach Login.
            if ($user['password_hash'] !== null) {
                throw new RuntimeException(
                    'Ein Konto mit dieser E-Mail und lokalem Login existiert bereits — '
                    . 'bitte zuerst lokal anmelden und SSO in den Kontoeinstellungen verknüpfen.',
                );
            }
            // Account-Takeover-Schutz für **offene Einladungen**: ein passwortloses
            // Konto im Status `invited` (oft frisch eingeladen, ggf. privilegiert)
            // darf NICHT allein über eine nicht ausdrücklich verifizierte IdP-E-Mail
            // (SAML liefert `email_verified`=null) beansprucht werden. Sonst könnte
            // ein IdP, der eine fremde E-Mail behauptet, eine ausstehende Einladung
            // kapern. Verifizierte OIDC-E-Mails (email_verified=true) sind erlaubt.
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
     * Merkt eine ausstehende SAML-AuthnRequest serverseitig und bindet den
     * (zufälligen) `RelayState` an Provider + Request-ID. Cookie-unabhängiger
     * Replay-Schutz: der IdP spiegelt den RelayState zurück, beim ACS wird er
     * **einmalig** eingelöst. Räumt abgelaufene Einträge opportunistisch ab.
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
     * Löst einen `RelayState` **einmalig** ein (atomar via `DELETE ... RETURNING`)
     * und liefert die gebundene Provider-ID + erwartete Request-ID — oder null,
     * wenn der RelayState unbekannt, abgelaufen oder bereits verbraucht ist
     * (Replay). Der ACS bindet die SAML-Antwort an genau diese Request-ID.
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
        while ($this->conn()->execute(
            'SELECT 1 FROM users WHERE lower(username) = lower(:u)',
            ['u' => $candidate],
        )->fetch() !== false) {
            $candidate = $base . ++$i;
        }

        return $candidate;
    }
}
