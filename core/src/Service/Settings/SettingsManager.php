<?php
declare(strict_types=1);

namespace App\Service\Settings;

use App\Audit\AuditLogger;
use App\Model\Table\SettingsTable;
use App\Service\Cache\CacheStore;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Central read/write service for the configuration store (ch. 1.4 / 23.3).
 *
 * - get(): returns the DB value or the safe default from the catalog (applies
 *   even without a DB entry, ch. 27.16.3).
 * - set(): validates against the catalog, encrypts secrets (AES-256-GCM), writes
 *   and audits within a single transaction. Footprint/UuidV7 via SettingsTable.
 */
class SettingsManager
{
    use LocatorAwareTrait;

    private SettingsCatalog $catalog;
    private AuditLogger $audit;
    private ?SecretCipher $cipher = null;
    private CacheStore $cache;
    private bool $tenantLoaded = false;
    private ?string $tenantMemo = null;

    public function __construct(?SettingsCatalog $catalog = null, ?AuditLogger $audit = null, ?CacheStore $cache = null)
    {
        $this->catalog = $catalog ?? new SettingsCatalog();
        $this->audit = $audit ?? new AuditLogger();
        $this->cache = $cache ?? new CacheStore('_app_settings_');
    }

    private function table(): SettingsTable
    {
        /** @var \App\Model\Table\SettingsTable $table */
        $table = $this->fetchTable('Settings');

        return $table;
    }

    private function cipher(): SecretCipher
    {
        return $this->cipher ??= new SecretCipher();
    }

    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        // Cached (non-secret) resolution: the cache holds {useDefault, value} of
        // the DB/catalog level; the **caller-specific** $default is only applied
        // here. Secrets are NEVER cached (see resolve()).
        $cached = $this->cache->get($this->cacheKey($namespace, $key));
        if (is_array($cached) && array_key_exists('value', $cached)) {
            return $cached['useDefault'] ? $default : $cached['value'];
        }

        [$cacheable, $useDefault, $value] = $this->resolve($namespace, $key);
        if ($cacheable) {
            $this->cache->set($this->cacheKey($namespace, $key), ['useDefault' => $useDefault, 'value' => $value]);
        }

        return $useDefault ? $default : $value;
    }

    /**
     * Resolves a value from DB + catalog.
     *
     * @return array{0:bool,1:bool,2:mixed} [cacheable, useDefault, value]
     */
    private function resolve(string $namespace, string $key): array
    {
        $tenant = $this->currentTenant();
        $cond = ['namespace' => $namespace, 'config_key' => $key];
        $cond = $tenant !== null
            ? $cond + ['OR' => [['tenant_id IS' => null], ['tenant_id' => $tenant]]]
            : $cond + ['tenant_id IS' => null];
        $rows = $this->table()->find()->where($cond)->all()->toArray();

        // A tenant-specific value takes precedence over the global one (tenant_id NULL).
        $row = null;
        if ($tenant !== null) {
            foreach ($rows as $r) {
                if ((string)($r->get('tenant_id') ?? '') === $tenant) {
                    $row = $r;
                    break;
                }
            }
        }
        if ($row === null) {
            foreach ($rows as $r) {
                if ($r->get('tenant_id') === null) {
                    $row = $r;
                    break;
                }
            }
        }

        if ($row === null) {
            $catalogDefault = $this->catalog->default($namespace, $key);
            // The catalog default is cacheable; without it the caller default decides.
            return $catalogDefault !== null
                ? [true, false, $catalogDefault]
                : [true, true, null];
        }

        if ($row->is_secret) {
            // Do NOT cache secrets (no plaintext in the file cache).
            $value = $row->value_encrypted !== null ? $this->cipher()->decrypt($row->value_encrypted) : null;

            return [false, false, $value];
        }

        return [true, false, $row->value];
    }

    private function cacheKey(string $namespace, string $key): string
    {
        // Include the tenant in the cache key so per-tenant values are not shared
        // across tenants. 'g' = global (no tenant context).
        return $namespace . '.' . $key . '.' . ($this->currentTenant() ?? 'g');
    }

    /** Current tenant from the RLS context (memoized per instance); NULL = global. */
    private function currentTenant(): ?string
    {
        if ($this->tenantLoaded) {
            return $this->tenantMemo;
        }
        $this->tenantLoaded = true;
        try {
            $row = $this->table()->getConnection()->execute(
                "SELECT nullif(current_setting('app.current_tenant_id', true), '') AS t",
            )->fetch('assoc');
            $this->tenantMemo = $row !== false && $row['t'] !== null && $row['t'] !== '' ? (string)$row['t'] : null;
        } catch (Throwable) {
            $this->tenantMemo = null;
        }

        return $this->tenantMemo;
    }

    public function set(string $namespace, string $key, mixed $value, ?string $tenantId = null): void
    {
        // Validation: mandatory for core.* (or known keys).
        if ($namespace === 'core' || $this->catalog->isKnown($namespace, $key)) {
            $errors = $this->catalog->validate($namespace, $key, $value);
            if ($errors) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
        }

        $secret = $this->catalog->isSecret($namespace, $key);
        $table = $this->table();

        $table->getConnection()->transactional(function () use ($table, $namespace, $key, $value, $secret, $tenantId): void {
            // Row for EXACTLY this level (global = tenant_id NULL, or tenant-specific).
            $find = $table->find()->where(['namespace' => $namespace, 'config_key' => $key]);
            $find = $tenantId === null ? $find->where(['tenant_id IS' => null]) : $find->where(['tenant_id' => $tenantId]);
            $row = $find->first();

            // Old value for the audit (never log secrets in plaintext).
            $old = $row === null || $row->is_secret ? null : $row->value;

            if ($row === null) {
                $row = $table->newEmptyEntity();
                $row->set('namespace', $namespace);
                $row->set('config_key', $key);
                $row->set('tenant_id', $tenantId);
            }
            $row->set('is_secret', $secret);
            if ($secret) {
                $row->set('value', null);
                $row->set('value_encrypted', $value === null ? null : $this->cipher()->encrypt((string)$value));
            } else {
                $row->set('value', $value);
                $row->set('value_encrypted', null);
            }

            if (!$table->save($row)) {
                throw new RuntimeException("Setting $namespace.$key konnte nicht gespeichert werden.");
            }

            $this->audit->log('config.update', 'core_setting', "$namespace.$key", [
                'tenant_id' => $tenantId,
                'oldValue' => $secret ? ['secret' => true] : ['value' => $old],
                'newValue' => $secret ? ['secret' => true] : ['value' => $value],
            ]);
        });

        // Clear the cache entirely: a changed global value also affects tenants
        // that fall back to it (their cache keys are not specifically known).
        // Settings writes are rare -> acceptable.
        $this->cache->clear();
    }
}
