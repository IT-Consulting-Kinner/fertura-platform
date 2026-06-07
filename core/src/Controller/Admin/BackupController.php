<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Backup\BackupService;
use App\Service\Settings\SettingsManager;

/**
 * Core-Backup-Verwaltung (Kap. 20.1.2 / E53/E55/E56) im Bereich
 * Core-Konfiguration. Erstellen (verifiziert, ggf. verschlüsselt), prüfen,
 * Probe-Restore, Löschen + Operationsprotokoll. Die **destruktive** Produktions-
 * Wiederherstellung bleibt bewusst CLI-only (`bin/cake backup restore … --yes`).
 */
class BackupController extends AdminController
{
    protected ?string $requiredArea = 'core_config';

    private function service(): BackupService
    {
        $actor = $this->identity() !== null ? (string)$this->identity()->getIdentifier() : null;

        return (new BackupService())->context('gui', $actor);
    }

    public function index(): void
    {
        $settings = new SettingsManager();
        $svc = new BackupService();
        $this->set('backups', $svc->list());
        $this->set('logEntries', $svc->logEntries(60));
        $this->set('configuredPath', $svc->base());
        $this->set('scheduleEnabled', (bool)$settings->get('core', 'backup.schedule.enabled', false));
        $this->set('scheduleHours', (int)$settings->get('core', 'backup.schedule.interval_hours', 24));
        $this->set('retention', (int)$settings->get('core', 'backup.retention', 14));
        $this->set('retentionDays', (int)$settings->get('core', 'backup.retention_days', 0));
        $this->set('encryptionOn', trim((string)$settings->get('core', 'backup.password', '')) !== '');
    }

    public function create()
    {
        $this->request->allowMethod('post');
        try {
            $path = (string)$this->request->getData('path') ?: null;
            $actor = $this->identity() !== null ? (string)$this->identity()->getIdentifier() : null;
            $id = $this->service()->create((string)$this->request->getData('note') ?: null, $actor, $path);
            $this->Flash->success(__('flash.backup.created', $id));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.backup.failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function verify(string $id)
    {
        $this->request->allowMethod('post');
        $v = $this->service()->verify($id);
        $v['ok'] ? $this->Flash->success(__('flash.backup.verify_ok')) : $this->Flash->error(__('flash.backup.verify_fail', (string)($v['reason'] ?? '')));

        return $this->redirect(['action' => 'index']);
    }

    public function testRestore(string $id)
    {
        $this->request->allowMethod('post');
        $t = $this->service()->testRestore($id);
        $t['ok'] ? $this->Flash->success(__('flash.backup.testrestore_ok', $t['tables'])) : $this->Flash->error(__('flash.backup.testrestore_fail', (string)($t['reason'] ?? '')));

        return $this->redirect(['action' => 'index']);
    }

    public function delete(string $id)
    {
        $this->request->allowMethod('post');
        $this->service()->delete($id) ? $this->Flash->success(__('flash.backup.deleted')) : $this->Flash->error(__('flash.backup.not_found'));

        return $this->redirect(['action' => 'index']);
    }

    /** Lädt das Archiv herunter (Datenexport) und protokolliert ihn. */
    public function download(string $id)
    {
        $row = (new BackupService())->get($id);
        if ($row === null || !is_file((string)$row['path'])) {
            $this->Flash->error(__('flash.backup.not_found'));

            return $this->redirect(['action' => 'index']);
        }
        $this->service()->logDownload($id);

        return $this->response->withFile((string)$row['path'], [
            'download' => true,
            'name' => basename((string)$row['path']),
        ]);
    }
}
