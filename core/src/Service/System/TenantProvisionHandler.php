<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Infrastructure\Db;
use App\Service\Tenant\TenantService;

/**
 * The tenant-provision critical action — Phase 6 (Increment 2).
 *
 * payload: {key, name, brand_name?, logo_url?}. execute() creates the (logical,
 * RLS-scoped) tenant row via {@see TenantService::create()}. verify() asserts the
 * tenant exists and is active. rollback() is a CLEAN action-own undo:
 * {@see TenantService::delete()} — gated (not the default tenant, no assigned users),
 * which a freshly-created tenant satisfies, so it removes the row + cascades. The
 * actual key the tenant got is captured in execute() so verify/rollback don't depend
 * on the enqueuer (one handler instance per worker tick — no cross-action leak).
 */
class TenantProvisionHandler implements CriticalActionHandler
{
    private ?string $createdId = null;

    public function __construct(private TenantService $tenants = new TenantService())
    {
    }

    public function type(): string
    {
        return 'tenant_provision';
    }

    public function execute(array $payload): void
    {
        $tenant = $this->tenants->create(
            (string)($payload['key'] ?? ''),
            (string)($payload['name'] ?? ''),
            isset($payload['brand_name']) ? (string)$payload['brand_name'] : null,
            isset($payload['logo_url']) ? (string)$payload['logo_url'] : null,
        );
        $this->createdId = $tenant['id'];
    }

    public function verify(array $payload): array
    {
        $id = $this->tenantId($payload);
        if ($id === '') {
            return ['ok' => false, 'reason' => 'tenant_id fehlt.'];
        }
        // Privileged read (the worker is superuser; tenants is tenant-scoped).
        $row = Db::privileged()->execute(
            'SELECT 1 FROM tenants WHERE id = :id AND active',
            ['id' => $id],
        )->fetch('assoc');
        if ($row === false) {
            return ['ok' => false, 'reason' => 'Mandant fehlt oder ist inaktiv nach Provisionierung: ' . $id];
        }

        return ['ok' => true, 'reason' => null];
    }

    public function rollback(array $payload): void
    {
        $id = $this->tenantId($payload);
        if ($id !== '') {
            // Gated delete: a freshly-created tenant has no users, so this is a clean
            // undo; if it cannot delete (e.g. users got assigned) it throws and the
            // runner escalates to needs_manual_restore.
            $this->tenants->delete($id);
        }
    }

    /** @param array<string,mixed> $payload */
    private function tenantId(array $payload): string
    {
        return $this->createdId ?? (string)($payload['tenant_id'] ?? '');
    }
}
