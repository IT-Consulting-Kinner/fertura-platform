<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Settings;

use App\Service\Cache\CacheStore;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test des Settings-Caches (P02): nicht-geheime Werte werden gecacht und bei
 * set() gezielt invalidiert; Geheimnisse werden NIE gecacht (kein Klartext im
 * Datei-Cache).
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
        // Cache-Schlüssel trägt den Mandanten-Suffix (.g = global/kein Mandant).
        $this->cache->delete('zztest.k1.g');

        $sm->set('zztest', 'k1', 'alpha');
        $this->assertSame('alpha', $sm->get('zztest', 'k1'));
        // Nach dem Lesen liegt die (nicht-geheime) Auflösung im Cache.
        $this->assertSame(['useDefault' => false, 'value' => 'alpha'], $this->cache->get('zztest.k1.g'));

        // Ändern muss den Cache invalidieren -> frischer Wert.
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

        $sm->set('core', 'health_token', $prev); // wiederherstellen
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
            $sm->set('core', 'session.timeout_minutes', 30, $tenantB);   // pro Mandant

            // Im Mandanten B -> mandantenspezifischer Wert.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantB]);
            $this->cache->clear();
            $this->assertSame(30, (new SettingsManager())->get('core', 'session.timeout_minutes'));

            // Ohne Mandantenkontext -> globaler Wert.
            $conn->execute("SELECT set_config('app.current_tenant_id', '', true)");
            $this->cache->clear();
            $this->assertSame(120, (new SettingsManager())->get('core', 'session.timeout_minutes'));
        } finally {
            $conn->rollback();
            $this->cache->clear();
        }
    }
}
