<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Schedule\ScheduledTaskInterface;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Automatic data backup at the configured interval (ch. 20.1.2).
 *
 * Ticked by the core worker via the {@see \App\Service\Schedule\ScheduledTaskRunner}.
 * Interval, activation and retention come from settings
 * (`backup.schedule.enabled`, `backup.schedule.interval_hours`, `backup.retention`).
 */
class BackupScheduledTask implements ScheduledTaskInterface
{
    public function key(): string
    {
        return 'backup.auto';
    }

    public function intervalSeconds(): int
    {
        $h = (int)(new SettingsManager())->get('core', 'backup.schedule.interval_hours', 24);

        return max(1, $h) * 3600;
    }

    public function run(): void
    {
        // Hard deployment switch: disables automatic backups entirely
        // (manual backups remain available via CLI/GUI).
        if (!\App\Service\System\FeatureFlags::enabled('backup_scheduler')) {
            return;
        }
        $settings = new SettingsManager();
        if (!(bool)$settings->get('core', 'backup.schedule.enabled', false)) {
            return; // scheduler disabled – no backup.
        }
        $service = (new BackupService())->context('scheduler', null);
        $id = $service->create('scheduled', null);
        $service->prune((int)$settings->get('core', 'backup.retention', 14));
        $service->pruneByAge((int)$settings->get('core', 'backup.retention_days', 0));

        // Off-site geo-redundancy (P14): additionally upload the freshly created
        // archive to object storage. Failures here must not invalidate the local
        // backup (isolated).
        if ((bool)$settings->get('core', 'backup.offsite.enabled', false)) {
            try {
                $row = ConnectionManager::get('default')
                    ->execute('SELECT path FROM backups WHERE id = :id', ['id' => $id])
                    ->fetch('assoc');
                if ($row !== false && is_file((string)$row['path'])) {
                    (new OffsiteBackupService())->upload((string)$row['path']);
                }
            } catch (Throwable) {
            }
        }
    }
}
