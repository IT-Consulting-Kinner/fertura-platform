<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Service\Backup\TenantBackupService;
use Cake\Http\Response;
use Throwable;

/**
 * Tenant admin GUI "Datensicherung" (tenant-backup design §5, Increment 6a): a
 * tenant admin backs up + downloads THEIR OWN tenant's data.
 *
 * Tenant-scoped area (in {@see AdminController::TENANT_AREAS}, no operator gate);
 * everything operates on `core.current_tenant()` + RLS, so a tenant never sees or
 * touches another tenant's backups. The destructive cross-tenant disaster-recovery
 * restore stays operator/CLI-only ({@see \App\Service\Backup\BackupService}); the
 * per-tenant scoped restore is Increment 6b.
 */
class TenantBackupController extends AdminController
{
    protected ?string $requiredArea = 'tenant_backup';

    public function index(): void
    {
        $svc = new TenantBackupService();
        $backups = $svc->listForTenant();
        $this->set('backups', $backups);
        // Scope choices for the ad-hoc backup form: full + core + each module key.
        $this->set('scopes', $svc->availableScopes());
    }

    public function create(): ?Response
    {
        $this->request->allowMethod('post');
        $note = trim((string)$this->request->getData('note'));
        $scope = trim((string)$this->request->getData('scope')) ?: 'full';
        try {
            $actor = $this->identity()?->getIdentifier();
            $id = (new TenantBackupService())->create(
                $scope,
                $note !== '' ? $note : null,
                is_scalar($actor) ? (string)$actor : null,
            );
            (new AuditLogger())->log('tenant.backup.create', 'tenant_backup', $id, ['component' => 'core']);
            $this->Flash->success(__('flash.tenant_backup.created'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    public function download(string $id): ?Response
    {
        $this->request->allowMethod('post');
        $path = (new TenantBackupService())->archivePath($id);
        if ($path === null) {
            $this->Flash->error(__('flash.tenant_backup.not_found'));

            return $this->redirect(['action' => 'index']);
        }
        (new AuditLogger())->log('tenant.backup.download', 'tenant_backup', $id, ['component' => 'core']);

        return $this->response->withFile($path, ['download' => true, 'name' => basename($path)]);
    }

    /**
     * DESTRUCTIVE per-tenant restore (Increment 6b): replaces THIS tenant's
     * restorable data with the chosen backup's. Scoped to `core.current_tenant()`
     * + RLS, so only the calling tenant is affected; the service audits the event.
     */
    public function restore(string $id): ?Response
    {
        $this->request->allowMethod('post');
        try {
            (new TenantBackupService())->restore($id);
            $this->Flash->success(__('flash.tenant_backup.restored'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * DESTRUCTIVE restore from an UPLOADED archive (Increment 6, upload-restore):
     * the tenant admin restores from a backup file they downloaded earlier, rather
     * than from a server-stored backup. Same safety as {@see self::restore} — the
     * service verifies the archive's manifest tenant matches the current tenant, so
     * an archive belonging to another tenant is rejected before any data is touched.
     */
    public function upload(): ?Response
    {
        $this->request->allowMethod('post');
        $file = $this->request->getUploadedFile('archive');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('flash.tenant_backup.upload_failed'));

            return $this->redirect(['action' => 'index']);
        }

        // Stage the upload to a scratch path the service can open; the @unlink in the
        // finally block removes it whether the restore succeeds or throws.
        $dir = TMP . 'tenant_backup_uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        try {
            $file->moveTo($tmp);
            (new TenantBackupService())->restoreFromUpload($tmp);
            (new AuditLogger())->log('tenant.backup.upload', 'tenant_backup', null, ['component' => 'core']);
            $this->Flash->success(__('flash.tenant_backup.restored'));
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        } finally {
            @unlink($tmp);
        }

        return $this->redirect(['action' => 'index']);
    }
}
