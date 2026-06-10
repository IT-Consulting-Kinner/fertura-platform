<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use App\Audit\AuditLogger;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use InvalidArgumentException;
use Throwable;

/**
 * Mandantenverwaltung (Wettbewerbs-Hebel 1/3). Verwaltet `core.tenants` und die
 * Zuordnung Benutzer → Mandant. Der **aktive** Mandant wird pro Request über den
 * RLS-Kontext gesetzt (`app.current_tenant_id`, siehe `RlsContext`) und von
 * mandanten-bezogenen Policies (`core.current_tenant()`) ausgewertet.
 *
 * Single-Org = ein Default-Mandant; nichts ändert sich, bis weitere Mandanten
 * angelegt und Benutzer zugeordnet werden.
 */
class TenantService
{
    /** Stabile ID des Default-Mandanten (vgl. Migration CoreTenancy). */
    public const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(private ?AuditLogger $audit = null)
    {
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    /**
     * Liefert die Mandanten-ID eines Benutzers (oder null). Fehlertolerant —
     * bricht im RLS-Middleware-Pfad keinen Request ab.
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
     * @return list<array{id:string,key:string,name:string,active:bool}>
     */
    public function all(): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, key, name, active FROM tenants ORDER BY name',
        )->fetchAll('assoc');

        return array_map(static fn (array $r): array => [
            'id' => (string)$r['id'],
            'key' => (string)$r['key'],
            'name' => (string)$r['name'],
            'active' => (bool)$r['active'],
        ], $rows);
    }

    /** @return array{id:string,key:string,name:string,active:bool}|null */
    public function get(string $id): ?array
    {
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
     * Legt einen Mandanten an. Schlüssel: kleinbuchstaben/-ziffern/-bindestrich.
     *
     * @return array{id:string,key:string,name:string,active:bool}
     */
    public function create(string $key, string $name): array
    {
        $key = strtolower(trim($key));
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9_-]{1,62}$/', $key) !== 1) {
            throw new InvalidArgumentException('Ungültiger Mandanten-Schlüssel (a-z, 0-9, _-; 2–63 Zeichen).');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Mandantenname darf nicht leer sein.');
        }
        $id = (string)$this->conn()->execute(
            'INSERT INTO tenants (key, name) VALUES (:k, :n) RETURNING id',
            ['k' => $key, 'n' => $name],
        )->fetch('assoc')['id'];
        $this->audit()->log('tenant.create', 'tenant', $id, ['key' => $key, 'name' => $name]);

        return ['id' => $id, 'key' => $key, 'name' => $name, 'active' => true];
    }

    /** Ordnet einen Benutzer einem Mandanten zu. */
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
