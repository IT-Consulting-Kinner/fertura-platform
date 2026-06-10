<?php
declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Schedule\ScheduledTaskInterface;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Automatisches Daten-Backup im konfigurierten Intervall (Kap. 20.1.2).
 *
 * Wird vom Core-Worker über den {@see \App\Service\Schedule\ScheduledTaskRunner}
 * getickt. Intervall + Aktivierung + Aufbewahrung stammen aus den Settings
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
        // Harter Deployment-Schalter: schaltet automatische Backups ganz ab
        // (manuelles Backup bleibt über CLI/GUI verfügbar).
        if (!\App\Service\System\FeatureFlags::enabled('backup_scheduler')) {
            return;
        }
        $settings = new SettingsManager();
        if (!(bool)$settings->get('core', 'backup.schedule.enabled', false)) {
            return; // Scheduler deaktiviert – kein Backup.
        }
        $service = (new BackupService())->context('scheduler', null);
        $id = $service->create('scheduled', null);
        $service->prune((int)$settings->get('core', 'backup.retention', 14));
        $service->pruneByAge((int)$settings->get('core', 'backup.retention_days', 0));

        // Off-Site-Geo-Redundanz (P14): das frisch erzeugte Archiv zusätzlich ins
        // Objekt-Storage laden. Fehler hier dürfen das lokale Backup nicht
        // entwerten (isoliert).
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
