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
        $this->cache->delete('zztest.k1');

        $sm->set('zztest', 'k1', 'alpha');
        $this->assertSame('alpha', $sm->get('zztest', 'k1'));
        // Nach dem Lesen liegt die (nicht-geheime) Auflösung im Cache.
        $this->assertSame(['useDefault' => false, 'value' => 'alpha'], $this->cache->get('zztest.k1'));

        // Ändern muss den Cache invalidieren -> frischer Wert.
        $sm->set('zztest', 'k1', 'beta');
        $this->assertSame('beta', $sm->get('zztest', 'k1'));

        ConnectionManager::get('default')->execute("DELETE FROM settings WHERE namespace = 'zztest'");
        $this->cache->delete('zztest.k1');
    }

    public function testSecretIsNeverCached(): void
    {
        $sm = new SettingsManager();
        $prev = $sm->get('core', 'health_token');
        $this->cache->delete('core.health_token');

        $sm->set('core', 'health_token', 'tok-123');
        $this->assertSame('tok-123', $sm->get('core', 'health_token'));
        $this->assertNull(
            $this->cache->get('core.health_token'),
            'Ein Geheimnis darf nicht im Datei-Cache landen.',
        );

        $sm->set('core', 'health_token', $prev); // wiederherstellen
        $this->cache->delete('core.health_token');
    }
}
