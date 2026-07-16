<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use InvalidArgumentException;
use Throwable;

/**
 * Tenant administration (competitive lever 1/3). Manages `core.tenants` and the
 * user → tenant assignment. The **active** tenant is set per request via the RLS
 * context (`app.current_tenant_id`, see `RlsContext`) and evaluated by
 * tenant-scoped policies (`core.current_tenant()`).
 *
 * Single-org = one default tenant; nothing changes until further tenants are
 * created and users assigned.
 */
class TenantService
{
    /** Stable ID of the default tenant (cf. migration CoreTenancy). */
    public const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(private ?AuditLogger $audit = null)
    {
    }

    private function conn(): Connection
    {
        // Narrow to the concrete Connection so execute()/transactional() resolve;
        // ConnectionInterface intentionally omits them (cf. TenantModuleService).
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    /**
     * Returns a user's tenant ID (or null). Fault-tolerant — does not abort any
     * request in the RLS-middleware path.
     */
    public function tenantIdForUser(string $userId): ?string
    {
        try {
            $row = $this->conn()->execute(
                'SELECT tenant_id FROM users WHERE id = :id',
                ['id' => $userId],
            )->fetch('assoc');

            return $row !== false && $row['tenant_id'] !== null ? (string)$row['tenant_id'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the CURRENT request tenant (RLS context) is the operator/default tenant.
     * NULL/unset context yields false (fail-closed). Used to scope the admin nav realms
     * to the viewer's tenant ({@see \App\Service\Admin\AdminNavBuilder::menu}).
     */
    public function currentTenantIsOperator(): bool
    {
        $row = $this->conn()->execute(
            'SELECT core.current_tenant() = :d AS ok',
            ['d' => self::DEFAULT_TENANT_ID],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }

    /**
     * Whether $tenantId must be MODULE-FREE — it is the operator/default tenant AND the
     * tenant topology mode is `multi_org` (the `core.tenancy.mode` app setting), so it
     * runs operator functions only and may neither enable nor use modules (operator-
     * tenant design §5b). In `single_org` mode (the default) nothing is module-free.
     * Delegates to the DB predicate {@see \core.tenant_is_module_free} so the rule has a
     * single source of truth. An invalid/unknown id is not module-free (callers keep
     * their own no-tenant path).
     */
    public function isModuleFreeTenant(string $tenantId): bool
    {
        if (!Uuid::isValid($tenantId)) {
            return false;
        }
        $row = $this->conn()->execute(
            'SELECT core.tenant_is_module_free(:t) AS ok',
            ['t' => $tenantId],
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }

    /**
     * Whether the CURRENT request tenant (RLS context) must be module-free
     * ({@see isModuleFreeTenant}). False when no tenant context is set (the DB
     * predicate is NULL for a NULL current tenant), so this never blocks the
     * tenant-less/CLI path.
     */
    public function currentTenantIsModuleFree(): bool
    {
        $row = $this->conn()->execute(
            'SELECT core.tenant_is_module_free(core.current_tenant()) AS ok',
        )->fetch('assoc');

        return $row !== false && ($row['ok'] === true || $row['ok'] === 't');
    }

    /**
     * @return list<array{id:string,key:string,name:string,active:bool}>
     */
    public function all(): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, key, name, active FROM tenants ORDER BY name',
        )->fetchAll('assoc');

        return array_map(static fn(array $r): array => [
            'id' => (string)$r['id'],
            'key' => (string)$r['key'],
            'name' => (string)$r['name'],
            'active' => (bool)$r['active'],
        ], $rows);
    }

    /** @return array{id:string,key:string,name:string,active:bool}|null */
    public function get(string $id): ?array
    {
        // UUID guard: the ID comes from GUI forms; treat malformed values like
        // unknown ones instead of 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->conn()->execute(
            'SELECT id, key, name, active FROM tenants WHERE id = :id',
            ['id' => $id],
        )->fetch('assoc');

        return $row === false ? null : [
            'id' => (string)$row['id'],
            'key' => (string)$row['key'],
            'name' => (string)$row['name'],
            'active' => (bool)$row['active'],
        ];
    }

    /**
     * Creates a tenant. Key: lowercase letters/digits/hyphens.
     *
     * @return array{id:string,key:string,name:string,active:bool}
     */
    public function create(string $key, string $name, ?string $brandName = null, ?string $logoUrl = null): array
    {
        $key = strtolower(trim($key));
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9_-]{1,62}$/', $key) !== 1) {
            throw new InvalidArgumentException('Ungültiger Mandanten-Schlüssel (a-z, 0-9, _-; 2–63 Zeichen).');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Mandantenname darf nicht leer sein.');
        }
        $brandName = $brandName !== null && trim($brandName) !== '' ? trim($brandName) : null;
        $logoUrl = $logoUrl !== null && trim($logoUrl) !== '' ? trim($logoUrl) : null;
        $id = (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name, brand_name, logo_url) VALUES (:k, :n, :b, :l) RETURNING id',
            ['k' => $key, 'n' => $name, 'b' => $brandName, 'l' => $logoUrl],
        )->fetch('assoc')['id'];
        $this->audit()->log('tenant.create', 'tenant', $id, ['key' => $key, 'name' => $name]);

        return ['id' => $id, 'key' => $key, 'name' => $name, 'active' => true];
    }

    /**
     * Branding of the **current** tenant (from the RLS context, e.g. pre-auth
     * resolved from the host) — for the login/SSO surface. Null = none/default.
     *
     * @return array{name:string, brand_name:?string, logo_url:?string}|null
     */
    public function currentBranding(): ?array
    {
        try {
            $row = $this->conn()->execute(
                'SELECT name, brand_name, logo_url FROM tenants '
                . "WHERE id = nullif(current_setting('app.current_tenant_id', true), '')::uuid AND active",
            )->fetch('assoc');
            if ($row === false) {
                return null;
            }

            return [
                'name' => (string)$row['name'],
                'brand_name' => $row['brand_name'] !== null ? (string)$row['brand_name'] : null,
                'logo_url' => $row['logo_url'] !== null ? (string)$row['logo_url'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Activates/deactivates (suspends) a tenant. The default tenant cannot be
     *  deactivated (single-org/break-glass protection). */
    public function setActive(string $tenantId, bool $active): void
    {
        if (!Uuid::isValid($tenantId)) {
            throw new InvalidArgumentException('Unbekannter Mandant.');
        }
        if (!$active && $tenantId === self::DEFAULT_TENANT_ID) {
            throw new InvalidArgumentException('Der Default-Mandant kann nicht deaktiviert werden.');
        }
        $this->conn()->execute(
            'UPDATE tenants SET active = :a WHERE id = :id',
            ['a' => $active ? 'true' : 'false', 'id' => $tenantId],
        );
        $this->audit()->log($active ? 'tenant.activate' : 'tenant.suspend', 'tenant', $tenantId, []);
    }

    /**
     * Deletes a tenant **including its data** (lifecycle). The default tenant is
     * protected; tenants that still have assigned users are rejected (reassign
     * first). The search/embedding index is deleted along with it (no ON DELETE
     * CASCADE FK), settings/SAML requests cascade via the FK.
     */
    public function delete(string $tenantId): void
    {
        if (!Uuid::isValid($tenantId)) {
            throw new InvalidArgumentException('Unbekannter Mandant.');
        }
        if ($tenantId === self::DEFAULT_TENANT_ID) {
            throw new InvalidArgumentException('Der Default-Mandant kann nicht gelöscht werden.');
        }
        $conn = $this->conn();
        $conn->transactional(function () use ($conn, $tenantId): void {
            $users = (int)$conn->execute(
                'SELECT count(*) AS c FROM users WHERE tenant_id = :t',
                ['t' => $tenantId],
            )->fetch('assoc')['c'];
            if ($users > 0) {
                throw new InvalidArgumentException(
                    "Mandant hat noch $users Benutzer — bitte zuerst neu zuordnen.",
                );
            }
            $conn->execute('DELETE FROM search_index WHERE tenant_id = :t', ['t' => $tenantId]);
            $conn->execute('DELETE FROM embeddings WHERE tenant_id = :t', ['t' => $tenantId]);
            // Deleting tenants cascades settings + saml_auth_requests (ON DELETE CASCADE).
            $conn->execute('DELETE FROM tenants WHERE id = :t', ['t' => $tenantId]);
            $this->audit()->log('tenant.delete', 'tenant', $tenantId, []);
        });
    }

    /** Assigns a user to a tenant. */
    public function assignUser(string $userId, string $tenantId): void
    {
        if ($this->get($tenantId) === null) {
            throw new InvalidArgumentException('Unbekannter Mandant.');
        }
        $this->conn()->execute(
            'UPDATE users SET tenant_id = :t WHERE id = :u',
            ['t' => $tenantId, 'u' => $userId],
        );
        $this->audit()->log('tenant.assign_user', 'user', $userId, ['tenant_id' => $tenantId]);
    }
}
