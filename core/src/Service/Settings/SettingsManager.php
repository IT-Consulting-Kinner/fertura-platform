<?php
declare(strict_types=1);

namespace App\Service\Settings;

use App\Audit\AuditLogger;
use App\Service\Cache\CacheStore;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use RuntimeException;

/**
 * Zentraler Lese-/Schreibdienst für den Konfigurationsspeicher (Kap. 1.4 / 23.3).
 *
 * - get(): liefert den DB-Wert oder den sicheren Vorgabewert aus dem Katalog
 *   (greift auch ohne DB-Eintrag, Kap. 27.16.3).
 * - set(): validiert gegen den Katalog, verschlüsselt Geheimnisse (AES-256-GCM),
 *   schreibt und auditiert in einer Transaktion. Footprint/UuidV7 via SettingsTable.
 */
class SettingsManager
{
    use LocatorAwareTrait;

    private SettingsCatalog $catalog;
    private AuditLogger $audit;
    private ?SecretCipher $cipher = null;
    private CacheStore $cache;

    public function __construct(?SettingsCatalog $catalog = null, ?AuditLogger $audit = null, ?CacheStore $cache = null)
    {
        $this->catalog = $catalog ?? new SettingsCatalog();
        $this->audit = $audit ?? new AuditLogger();
        $this->cache = $cache ?? new CacheStore('_app_settings_');
    }

    private function table(): \App\Model\Table\SettingsTable
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
        // Gecachte (nicht-geheime) Auflösung: Cache hält {useDefault, value} der
        // DB-/Katalog-Ebene; der **aufrufer-spezifische** $default wird erst hier
        // angewandt. Geheimnisse werden NIE gecacht (s. resolve()).
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
     * Löst einen Wert aus DB + Katalog auf.
     *
     * @return array{0:bool,1:bool,2:mixed} [cacheable, useDefault, value]
     */
    private function resolve(string $namespace, string $key): array
    {
        $row = $this->table()->find()
            ->where(['namespace' => $namespace, 'config_key' => $key])
            ->first();

        if ($row === null) {
            $catalogDefault = $this->catalog->default($namespace, $key);
            // Katalog-Default ist cachebar; ohne ihn entscheidet der Aufrufer-Default.
            return $catalogDefault !== null
                ? [true, false, $catalogDefault]
                : [true, true, null];
        }

        if ($row->is_secret) {
            // Geheimnisse NICHT cachen (kein Klartext im Datei-Cache).
            $value = $row->value_encrypted !== null ? $this->cipher()->decrypt($row->value_encrypted) : null;

            return [false, false, $value];
        }

        return [true, false, $row->value];
    }

    private function cacheKey(string $namespace, string $key): string
    {
        return $namespace . '.' . $key;
    }

    public function set(string $namespace, string $key, mixed $value): void
    {
        // Validierung: für core.* (oder bekannte Keys) verpflichtend.
        if ($namespace === 'core' || $this->catalog->isKnown($namespace, $key)) {
            $errors = $this->catalog->validate($namespace, $key, $value);
            if ($errors) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
        }

        $secret = $this->catalog->isSecret($namespace, $key);
        $table = $this->table();

        $table->getConnection()->transactional(function () use ($table, $namespace, $key, $value, $secret): void {
            $row = $table->find()
                ->where(['namespace' => $namespace, 'config_key' => $key])
                ->first();

            // Alten Wert für das Audit (Geheimnisse niemals im Klartext loggen).
            $old = ($row === null || $row->is_secret) ? null : $row->value;

            if ($row === null) {
                $row = $table->newEmptyEntity();
                $row->set('namespace', $namespace);
                $row->set('config_key', $key);
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
                'oldValue' => $secret ? ['secret' => true] : ['value' => $old],
                'newValue' => $secret ? ['secret' => true] : ['value' => $value],
            ]);
        });

        // Gezielte Cache-Invalidierung nach erfolgreicher Schreib-Transaktion.
        $this->cache->delete($this->cacheKey($namespace, $key));
    }
}
