<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Durable TOTP replay protection (peer-review F12): the last accepted TOTP time
 * step per user. Previously kept only in a best-effort cache (`_app_`), so a cache
 * outage/eviction silently re-enabled replay of an intercepted code. Persisting it
 * on core.users makes the check-and-set authoritative and fail-closed. Nullable:
 * NULL means "no code accepted yet" (treated as 0).
 */
class CoreUserTotpLastStep extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.users ADD COLUMN totp_last_step bigint NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.users DROP COLUMN IF EXISTS totp_last_step');
    }
}
