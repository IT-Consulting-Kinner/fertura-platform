<?php
declare(strict_types=1);

namespace App\Service\Settings;

use App\Audit\AuditLogger;
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

    public function __construct(?SettingsCatalog $catalog = null, ?AuditLogger $audit = null)
    {
        $this->catalog = $catalog ?? new SettingsCatalog();
        $this->audit = $audit ?? new AuditLogger();
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
        $row = $this->table()->find()
            ->where(['namespace' => $namespace, 'config_key' => $key])
            ->first();

        if ($row === null) {
            $catalogDefault = $this->catalog->default($namespace, $key);

            return $catalogDefault ?? $default;
        }

        if ($row->is_secret) {
            return $row->value_encrypted !== null ? $this->cipher()->decrypt($row->value_encrypted) : null;
        }

        return $row->value;
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
    }
}
