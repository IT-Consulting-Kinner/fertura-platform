<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Vertrauenskette Root -> Publisher (Aufgabe 1 / Kap. 24.9.2).
 *
 * Persistiert je Publisher-Anker die Root-Signatur über sein Zertifikat
 * (key_signature), damit die Kette jederzeit nachprüfbar ist: Ein Publisher-
 * Schlüssel ist nur vertrauenswürdig, wenn seine Identität (key_id, public_key,
 * publisher) durch einen aktiven Root-Schlüssel signiert wurde.
 */
class CoreTrustChain extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.trust_anchors ADD COLUMN IF NOT EXISTS key_signature text NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.trust_anchors DROP COLUMN IF EXISTS key_signature');
    }
}
