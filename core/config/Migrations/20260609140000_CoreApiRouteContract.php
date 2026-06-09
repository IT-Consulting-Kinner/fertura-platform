<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Contract `core.api.route` (Programm Tier-1, P07): Erweiterungspunkt, über
 * den Module externe API-Endpunkte bereitstellen (Manifest-Sektion `api_routes`,
 * Handler implementiert `App\Service\Api\ApiEndpointInterface`). Der Core routet
 * `/api/v1/m/<key>/…` darauf (in-process oder über RPC).
 *
 * Deklarativ geseedet (Sichtbarkeit in der Contract-Registry), analog zu
 * `core.collector.scheduled`.
 */
class CoreApiRouteContract extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.api.route', 'service', '1.0.0', true, true,
                 'Modul-bereitgestellte externe API-Endpunkte (Manifest api_routes, ApiEndpointInterface).')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.api.route'");
    }
}
