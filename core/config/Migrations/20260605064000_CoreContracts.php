<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Contract-/Capability-Registry (Step 5, Kap. 26 / 26.6.4).
 *
 * - contracts: registrierte Erweiterungspunkte (resolver/collector/event/service).
 * - contract_registrations: Provider/Collector-Beiträge/Listener/Service-Nutzer.
 *   Slot-Exklusivität (genau 1 aktiver Provider, Entscheidung 129/174) per
 *   partiellem Unique-Index erzwungen.
 * - capability_bindings: persistierte Bindungen (Modul ↔ Contract) für Audit +
 *   Laufzeit-Guard (Entscheidung 151).
 *
 * Module werden per Text-Schlüssel (module_key) referenziert (entkoppelt von der
 * modules-Tabelle aus Step 7; referenzrobust).
 */
class CoreContracts extends BaseMigration
{
    public function up(): void
    {
        // ---- contracts ------------------------------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.contracts (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                owner_module_key text        NOT NULL,
                name             text        NOT NULL,
                contract_type    text        NOT NULL,
                version          text        NOT NULL,
                input_spec       jsonb       NULL,
                output_spec      jsonb       NULL,
                default_behavior jsonb       NULL,
                multi_use        boolean     NOT NULL DEFAULT true,
                description      text        NULL,
                active           boolean     NOT NULL DEFAULT true,
                deactivated_at   timestamptz NULL,
                created_by       uuid        NULL,
                updated_by       uuid        NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_contracts_name UNIQUE (name),
                CONSTRAINT ck_contracts_type
                    CHECK (contract_type IN ('resolver','collector','event','service')),
                CONSTRAINT ck_contracts_version
                    CHECK (version ~ '^\d+\.\d+\.\d+$'),
                CONSTRAINT fk_contracts_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_contracts_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE INDEX ix_contracts_type ON core.contracts (contract_type, active)');
        $this->execute('CREATE INDEX ix_contracts_owner ON core.contracts (owner_module_key)');
        $this->execute(
            'CREATE TRIGGER trg_contracts_set_updated_at BEFORE UPDATE ON core.contracts '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // ---- contract_registrations ----------------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.contract_registrations (
                id                   uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                contract_id          uuid        NOT NULL,
                module_key           text        NOT NULL,
                module_version       text        NULL,
                registration_type    text        NOT NULL,
                implementation_class text        NULL,
                required_version     text        NULL,
                priority             int         NOT NULL DEFAULT 0,
                active               boolean     NOT NULL DEFAULT true,
                deactivated_at       timestamptz NULL,
                created_by           uuid        NULL,
                updated_by           uuid        NULL,
                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_registrations_contract
                    FOREIGN KEY (contract_id) REFERENCES core.contracts(id) ON DELETE CASCADE,
                CONSTRAINT ck_registrations_type
                    CHECK (registration_type IN
                        ('provider','collector_contribution','listener','service_consumer')),
                CONSTRAINT fk_registrations_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_registrations_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        // Slot-Exklusivität: höchstens 1 aktiver Provider pro Contract.
        $this->execute(
            'CREATE UNIQUE INDEX uq_registrations_active_provider '
            . "ON core.contract_registrations (contract_id) "
            . "WHERE registration_type = 'provider' AND active"
        );
        $this->execute(
            'CREATE INDEX ix_registrations_contract '
            . 'ON core.contract_registrations (contract_id, registration_type, active)'
        );
        $this->execute('CREATE INDEX ix_registrations_module ON core.contract_registrations (module_key)');
        $this->execute(
            'CREATE TRIGGER trg_registrations_set_updated_at BEFORE UPDATE ON core.contract_registrations '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        // ---- capability_bindings -------------------------------------------
        $this->execute(<<<'SQL'
            CREATE TABLE core.capability_bindings (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_key       text        NOT NULL,
                contract_id      uuid        NOT NULL,
                required_version text        NULL,
                status           text        NOT NULL DEFAULT 'active',
                revoked_at       timestamptz NULL,
                created_by       uuid        NULL,
                updated_by       uuid        NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_bindings_contract
                    FOREIGN KEY (contract_id) REFERENCES core.contracts(id) ON DELETE CASCADE,
                CONSTRAINT ck_bindings_status CHECK (status IN ('active','revoked')),
                CONSTRAINT uq_bindings_module_contract UNIQUE (module_key, contract_id),
                CONSTRAINT fk_bindings_created_by
                    FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT fk_bindings_updated_by
                    FOREIGN KEY (updated_by) REFERENCES core.users(id) ON DELETE SET NULL
            )
            SQL);
        $this->execute('CREATE INDEX ix_bindings_contract ON core.capability_bindings (contract_id, status)');
        $this->execute(
            'CREATE TRIGGER trg_bindings_set_updated_at BEFORE UPDATE ON core.capability_bindings '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.capability_bindings');
        $this->execute('DROP TABLE IF EXISTS core.contract_registrations');
        $this->execute('DROP TABLE IF EXISTS core.contracts');
    }
}
