<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Observability (Step 12, Kap. 20.2).
 *
 * - core.worker_heartbeats: letzter Lauf/Status je CLI-Worker (Kap. 20.3:
 *   „letzter erfolgreicher Lauf mit Zeitstempel"). Bewusst getrennt von der
 *   katalog-gepflegten core.settings (Laufzeitzustand statt validierte Konfig).
 * - Seed des Core-Collector-Contracts core.collector.health (Kap. 20.2.2):
 *   Module tragen eigene Health-Checks als Collector-Beiträge bei.
 */
class CoreHealth extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.worker_heartbeats (
                worker_key  text        NOT NULL PRIMARY KEY,
                last_run_at timestamptz NOT NULL DEFAULT now(),
                last_status text        NOT NULL DEFAULT 'ok',
                detail      jsonb       NULL,
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_worker_heartbeats_status
                    CHECK (last_status IN ('ok', 'warn', 'error'))
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_worker_heartbeats_set_updated_at BEFORE UPDATE ON core.worker_heartbeats '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // Core-Collector-Contract für Modul-Health-Beiträge (Kap. 20.2.2).
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.health', 'collector', '1.0.0', true, true,
                 'Sammelt Health-Beitraege der Module (Kap. 20.2.2). Implementierungen liefern App\Service\Health\HealthCheckInterface.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.health'");
        $this->execute('DROP TABLE IF EXISTS core.worker_heartbeats');
    }
}
