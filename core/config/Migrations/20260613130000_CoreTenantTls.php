<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Custom-domain portal binding + operator-managed TLS certificates.
 *
 * Two tables on top of the existing host->tenant resolution (`tenants.domain`,
 * {@see \App\Service\Tenant\TenantResolver}):
 *
 * - `core.tenant_domains` — a tenant's custom hostname(s) for a public module
 *   portal, with an ownership-verification token and an activation status. This
 *   supersedes the single `tenants.domain` column (which stays for backward
 *   compatibility) by allowing N verified hosts per tenant, each bound to a
 *   specific module portal.
 * - `core.tenant_domain_certs` — operator-managed TLS certificates per domain.
 *   The platform stores, validates and warns; it does NOT terminate TLS (that is
 *   the edge/nginx) and does NOT auto-renew. The private key is stored
 *   **AES-256-GCM-encrypted** (SecretCipher); a plaintext key never hits the DB.
 *   At most one `active` cert per domain (partial unique index).
 */
class CoreTenantTls extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.tenant_domains (
                id                 uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                tenant_id          uuid        NOT NULL,
                host               text        NOT NULL,
                purpose            text        NOT NULL DEFAULT 'portal',
                portal_module_key  text        NULL,
                verification_token text        NOT NULL,
                verified_at        timestamptz NULL,
                status             text        NOT NULL DEFAULT 'pending',
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_tenant_domains_tenant
                    FOREIGN KEY (tenant_id) REFERENCES core.tenants (id) ON DELETE CASCADE,
                CONSTRAINT uq_tenant_domains_host UNIQUE (host),
                CONSTRAINT ck_tenant_domains_status CHECK (status IN ('pending', 'active', 'disabled'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_tenant_domains_tenant ON core.tenant_domains (tenant_id)');
        $this->execute(
            'CREATE TRIGGER trg_tenant_domains_updated_at BEFORE UPDATE ON core.tenant_domains '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.tenant_domain_certs (
                id                 uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                domain_id          uuid        NOT NULL,
                cert_pem           text        NOT NULL,
                chain_pem          text        NULL,
                key_cipher         text        NOT NULL,
                subject_cn         text        NULL,
                sans               jsonb       NOT NULL DEFAULT '[]'::jsonb,
                not_before         timestamptz NULL,
                not_after          timestamptz NOT NULL,
                fingerprint_sha256 text        NOT NULL,
                status             text        NOT NULL DEFAULT 'pending_deploy',
                uploaded_by        uuid        NULL,
                uploaded_at        timestamptz NOT NULL DEFAULT now(),
                deployed_at        timestamptz NULL,
                CONSTRAINT fk_tenant_domain_certs_domain
                    FOREIGN KEY (domain_id) REFERENCES core.tenant_domains (id) ON DELETE CASCADE,
                CONSTRAINT ck_tenant_domain_certs_status
                    CHECK (status IN ('pending_deploy', 'active', 'superseded', 'invalid'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_tenant_domain_certs_domain ON core.tenant_domain_certs (domain_id)');
        // Expiry scan (warning task) hits only the certs that can still serve.
        $this->execute(
            'CREATE INDEX ix_tenant_domain_certs_expiry ON core.tenant_domain_certs (not_after) '
            . "WHERE status IN ('active', 'pending_deploy')",
        );
        // At most one active (= currently deployed) cert per domain.
        $this->execute(
            'CREATE UNIQUE INDEX uq_tenant_domain_certs_active ON core.tenant_domain_certs (domain_id) '
            . "WHERE status = 'active'",
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.tenant_domain_certs');
        $this->execute('DROP TABLE IF EXISTS core.tenant_domains');
    }
}
