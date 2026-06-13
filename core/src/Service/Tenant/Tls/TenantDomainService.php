<?php
declare(strict_types=1);

namespace App\Service\Tenant\Tls;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * Custom domains of a tenant for public module portals (E158).
 *
 * A domain is registered as `pending` with an ownership-verification token (the
 * tenant proves control via a DNS TXT record / well-known path before the
 * operator activates and deploys). Only `active` + verified domains are eligible
 * to be served at the edge. Host resolution to a tenant still runs through
 * {@see \App\Service\Tenant\TenantResolver}; this table is the richer,
 * multi-host source of truth on top of the single `tenants.domain` column.
 */
class TenantDomainService
{
    private function conn(): Connection
    {
        /** @var \Cake\Database\Connection $c */
        $c = ConnectionManager::get('default');

        return $c;
    }

    /** Validates a DNS hostname (labels, length); no scheme, no path, no port. */
    public function isValidHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host !== ''
            && strlen($host) <= 253
            && preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*)(\.[a-z0-9](-?[a-z0-9])*)+$/', $host) === 1;
    }

    /**
     * Registers a custom domain for a tenant (status `pending`) and returns its
     * id plus the verification token the tenant must publish.
     *
     * @return array{id: string, host: string, verification_token: string, status: string}
     * @throws \App\Service\Tenant\Tls\TlsCertException on an invalid host
     */
    public function create(
        string $tenantId,
        string $host,
        string $purpose = 'portal',
        ?string $portalModuleKey = null,
    ): array {
        $host = strtolower(trim($host));
        if (!$this->isValidHost($host)) {
            throw new TlsCertException("Ungültiger Hostname: '$host'.");
        }
        $token = 'fertura-domain-verify=' . bin2hex(random_bytes(16));

        $row = $this->conn()->execute(
            'INSERT INTO tenant_domains (tenant_id, host, purpose, portal_module_key, verification_token) '
            . 'VALUES (:t, :h, :p, :m, :tok) RETURNING id, host, verification_token, status',
            ['t' => $tenantId, 'h' => $host, 'p' => $purpose, 'm' => $portalModuleKey, 'tok' => $token],
        )->fetch('assoc');

        return [
            'id' => (string)$row['id'],
            'host' => (string)$row['host'],
            'verification_token' => (string)$row['verification_token'],
            'status' => (string)$row['status'],
        ];
    }

    /**
     * Marks a pending domain as verified and active (operator step, after the
     * ownership token has been confirmed). Idempotent for already-active domains.
     */
    public function markVerified(string $domainId): void
    {
        $this->conn()->execute(
            "UPDATE tenant_domains SET verified_at = now(), status = 'active' "
            . "WHERE id = :id AND status <> 'disabled'",
            ['id' => $domainId],
        );
    }

    /** Disables a domain (kept for history; no longer eligible to be served). */
    public function disable(string $domainId): void
    {
        $this->conn()->execute(
            "UPDATE tenant_domains SET status = 'disabled' WHERE id = :id",
            ['id' => $domainId],
        );
    }

    /**
     * Domains of a tenant (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForTenant(string $tenantId): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, host, purpose, portal_module_key, verified_at, status, created_at '
            . 'FROM tenant_domains WHERE tenant_id = :t ORDER BY created_at DESC',
            ['t' => $tenantId],
        )->fetchAll('assoc');

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(string $domainId): ?array
    {
        $row = $this->conn()->execute(
            'SELECT * FROM tenant_domains WHERE id = :id',
            ['id' => $domainId],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }
}
