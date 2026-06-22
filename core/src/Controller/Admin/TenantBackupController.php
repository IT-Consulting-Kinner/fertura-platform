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
        $backups = (new TenantBackupService())->listForTenant();
        $this->set(compact('backups'));
    }

    public function create(): ?Response
    {
        $this->request->allowMethod('post');
        $note = trim((string)$this->request->getData('note'));
        try {
            $actor = $this->identity()?->getIdentifier();
            $id = (new TenantBackupService())->create(
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
}
