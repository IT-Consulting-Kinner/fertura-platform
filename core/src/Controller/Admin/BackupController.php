<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Backup\BackupService;

/**
 * Core-Backup-Verwaltung (Kap. 20.1 / E53) im Bereich Core-Konfiguration.
 * Erstellen, prüfen (Checksumme), Probe-Restore (Scratch-DB) und Löschen. Die
 * **destruktive** Produktions-Wiederherstellung bleibt bewusst CLI-only
 * (`bin/cake backup restore <id> --yes`).
 */
class BackupController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    public function index(): void
    {
        $settings = new \App\Service\Settings\SettingsManager();
        $this->set('backups', (new BackupService())->list());
        $this->set('configuredPath', (new BackupService())->base());
        $this->set('scheduleEnabled', (bool)$settings->get('core', 'backup.schedule.enabled', false));
        $this->set('scheduleHours', (int)$settings->get('core', 'backup.schedule.interval_hours', 24));
        $this->set('retention', (int)$settings->get('core', 'backup.retention', 14));
    }

    public function create()
    {
        $this->request->allowMethod('post');
        try {
            $actor = $this->identity() !== null ? (string)$this->identity()->getIdentifier() : null;
            $path = (string)$this->request->getData('path') ?: null;
            $id = (new BackupService())->create((string)$this->request->getData('note') ?: null, $actor, $path);
            $this->Flash->success(__('flash.backup.created', $id));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.backup.failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function verify(string $id)
    {
        $this->request->allowMethod('post');
        $v = (new BackupService())->verify($id);
        $v['ok'] ? $this->Flash->success(__('flash.backup.verify_ok')) : $this->Flash->error(__('flash.backup.verify_fail', (string)($v['reason'] ?? '')));

        return $this->redirect(['action' => 'index']);
    }

    public function testRestore(string $id)
    {
        $this->request->allowMethod('post');
        $t = (new BackupService())->testRestore($id);
        $t['ok'] ? $this->Flash->success(__('flash.backup.testrestore_ok', $t['tables'])) : $this->Flash->error(__('flash.backup.testrestore_fail', (string)($t['reason'] ?? '')));

        return $this->redirect(['action' => 'index']);
    }

    public function delete(string $id)
    {
        $this->request->allowMethod('post');
        (new BackupService())->delete($id) ? $this->Flash->success(__('flash.backup.deleted')) : $this->Flash->error(__('flash.backup.not_found'));

        return $this->redirect(['action' => 'index']);
    }
}
