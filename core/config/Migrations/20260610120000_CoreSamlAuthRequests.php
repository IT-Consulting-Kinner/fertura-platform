<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * SAML-Replay-Schutz unabhängig vom Session-Cookie (Peer-Review #2 / E98).
 *
 * Beim SP-initiierten SAML-Login wird die ACS-Antwort vom IdP **cross-site** an
 * den SP zurück-ge-POSTet. Ein `SameSite=Lax/Strict`-Session-Cookie wird dabei
 * vom Browser NICHT mitgesendet — eine rein session-basierte `InResponseTo`-
 * Bindung wäre also in der Praxis oft wirkungslos.
 *
 * Stattdessen wird ein zufälliger `RelayState` (vom IdP unverändert zurück-
 * gespiegelt, cookie-unabhängig) serverseitig an die ausgestellte AuthnRequest-
 * ID + den Provider gebunden und beim ACS **einmalig** eingelöst (atomar via
 * `DELETE ... RETURNING`). So ist jede Antwort an genau eine eigene Anfrage
 * gebunden und nicht wiederholbar — ohne Abhängigkeit vom Session-Cookie.
 */
class CoreSamlAuthRequests extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.saml_auth_requests (
                relay_state text        NOT NULL PRIMARY KEY,
                provider_id uuid        NOT NULL REFERENCES core.sso_providers (id) ON DELETE CASCADE,
                request_id  text        NOT NULL,
                expires_at  timestamptz NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute(
            'CREATE INDEX ix_saml_auth_requests_expires ON core.saml_auth_requests (expires_at)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.saml_auth_requests');
    }
}
