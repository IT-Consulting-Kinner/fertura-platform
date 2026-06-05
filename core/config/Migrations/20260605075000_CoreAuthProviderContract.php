<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Resolver-Slot für die Authentifizierungsmethode (Kap. 27.2.2): Der Core
 * definiert den Contract `core.auth.provider`; ein Extension-Modul (z. B.
 * OIDC/SAML/AD) registriert dafür einen Provider. Ohne aktiven Provider greift
 * der lokale Default (`App\Service\Auth\LocalAuthProvider`).
 *
 * Resolver-Regel (Kap. 26.7): genau ein aktiver Provider pro Slot.
 */
class CoreAuthProviderContract extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.auth.provider', 'resolver', '1.0.0', false, true,
                 'Austauschbare Authentifizierungsmethode (Kap. 27.2.2). Ein Provider implementiert App\Service\Auth\AuthProviderInterface; ohne aktiven Provider greift der lokale Default.')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.auth.provider'");
    }
}
