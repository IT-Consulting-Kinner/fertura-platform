<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Settings;

use App\Service\Cache\CacheStore;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the settings cache (P02): non-secret values are cached and selectively
 * invalidated on set(); secrets are NEVER cached (no plaintext in the file
 * cache).
 */
class SettingsManagerCacheTest extends TestCase
{
    private CacheStore $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new CacheStore('_app_settings_');
    }

    public function testNonSecretIsCachedAndInvalidatedOnSet(): void
    {
        $sm = new SettingsManager();
        // The cache key carries the tenant suffix (.g = global / no tenant).
        $this->cache->delete('zztest.k1.g');

        $sm->set('zztest', 'k1', 'alpha');
        $this->assertSame('alpha', $sm->get('zztest', 'k1'));
        // After reading, the (non-secret) resolved value sits in the cache.
        $this->assertSame(['useDefault' => false, 'value' => 'alpha'], $this->cache->get('zztest.k1.g'));

        // Changing the value must invalidate the cache -> fresh value.
        $sm->set('zztest', 'k1', 'beta');
        $this->assertSame('beta', $sm->get('zztest', 'k1'));

        ConnectionManager::get('default')->execute("DELETE FROM settings WHERE namespace = 'zztest'");
        $this->cache->delete('zztest.k1.g');
    }

    public function testSecretIsNeverCached(): void
    {
        $sm = new SettingsManager();
        $prev = $sm->get('core', 'health_token');
        $this->cache->delete('core.health_token.g');

        $sm->set('core', 'health_token', 'tok-123');
        $this->assertSame('tok-123', $sm->get('core', 'health_token'));
        $this->assertNull(
            $this->cache->get('core.health_token.g'),
            'Ein Geheimnis darf nicht im Datei-Cache landen.',
        );

        $sm->set('core', 'health_token', $prev); // restore
        $this->cache->delete('core.health_token.g');
    }

    public function testPerTenantOverrideWinsOverGlobal(): void
    {
        $conn = ConnectionManager::get('default');
        $conn->begin();
        try {
            $tenantB = (new \App\Service\Tenant\TenantService())->create('zztest-cfg', 'CFG')['id'];
            $sm = new SettingsManager();
            $sm->set('core', 'session.timeout_minutes', 120);            // global
            $sm->set('core', 'session.timeout_minutes', 30, $tenantB);   // per tenant

            // Within tenant B -> tenant-specific value.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantB]);
            $this->cache->clear();
            $this->assertSame(30, (new SettingsManager())->get('core', 'session.timeout_minutes'));

            // Without tenant context -> global value.
            $conn->execute("SELECT set_config('app.current_tenant_id', '', true)");
            $this->cache->clear();
            $this->assertSame(120, (new SettingsManager())->get('core', 'session.timeout_minutes'));
        } finally {
            $conn->rollback();
            $this->cache->clear();
        }
    }
}
