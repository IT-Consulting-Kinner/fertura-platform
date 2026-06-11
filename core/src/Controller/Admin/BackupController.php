<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Backup\BackupService;
use App\Service\Backup\OffsiteBackupService;
use App\Service\Settings\SettingsManager;

/**
 * Core backup management (ch. 20.1.2 / E53/E55/E56) within the
 * core configuration area. Create (verified, optionally encrypted), verify,
 * test-restore, delete + operations log. The **destructive** production
 * restore deliberately stays CLI-only (`bin/cake backup restore … --yes`).
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
        $this->set('encryptionOn', $svc->encryptionEnabled());

        // Off-site (P14): only show when enabled; tolerate storage errors.
        $offsiteEnabled = (bool)$settings->get('core', 'backup.offsite.enabled', false);
        $offsiteBackups = [];
        if ($offsiteEnabled) {
            try {
                $offsiteBackups = (new OffsiteBackupService())->list();
            } catch (\Throwable) {
                $offsiteBackups = [];
            }
        }
        $this->set(compact('offsiteEnabled', 'offsiteBackups'));
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

    /** Downloads the archive (data export) and logs the download. */
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

    /** Uploads a local backup to the off-site object storage (P14). */
    public function offsiteUpload(string $id): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if (!$this->offsiteEnabled()) {
            $this->Flash->error(__('flash.backup.offsite_disabled'));

            return $this->redirect(['action' => 'index']);
        }
        $row = (new BackupService())->get($id);
        if ($row === null || !is_file((string)$row['path'])) {
            $this->Flash->error(__('flash.backup.not_found'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            (new OffsiteBackupService())->upload((string)$row['path']);
            $this->Flash->success(__('flash.backup.offsite_uploaded'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.backup.offsite_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    /** Deletes an off-site backup (name = file name, no path traversal). */
    public function offsiteDelete(string $name): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if (!$this->offsiteEnabled() || !preg_match('/^[A-Za-z0-9._-]+\z/', $name)) {
            $this->Flash->error(__('flash.backup.offsite_disabled'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            (new OffsiteBackupService())->delete($name);
            $this->Flash->success(__('flash.backup.offsite_deleted'));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.backup.offsite_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    private function offsiteEnabled(): bool
    {
        return (bool)(new SettingsManager())->get('core', 'backup.offsite.enabled', false);
    }
}
