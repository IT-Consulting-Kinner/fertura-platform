<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Index für die Per-IP-Anmeldedrosselung (Peer-Review #3 / E100).
 *
 * `LoginThrottleMiddleware` ruft bei JEDEM `/login`-POST `recentIpFailures($ip)`
 * auf (`WHERE ip_address = :ip AND occurred_at > …`). Ohne passenden Index ist das
 * ein Seq-Scan auf `auth_failures` — und der Schutz greift genau dann am
 * stärksten, wenn die Tabelle (unter Spraying) am größten ist. Der zusammengesetzte
 * Index (ip_address, occurred_at) macht die Abfrage index-gestützt.
 */
class CoreAuthFailuresIpIndex extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            'CREATE INDEX IF NOT EXISTS ix_auth_failures_ip_time '
            . 'ON core.auth_failures (ip_address, occurred_at)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.ix_auth_failures_ip_time');
    }
}
