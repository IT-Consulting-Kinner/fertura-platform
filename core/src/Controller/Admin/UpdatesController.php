<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application;
use App\Service\Update\UpdateManager;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Update manager (admin area "Update Manager").
 *
 * Shows the update history and allows triggering module/core updates
 * from a provided (signed) package path. On failure, a down-migration
 * rollback is performed in accordance with step 8.
 */
class UpdatesController extends AdminController
{
    protected ?string $requiredArea = 'update_manager';

    public function index(): void
    {
        $history = (new UpdateManager())->listHistory();
        $modules = ConnectionManager::get('default')->execute(
            'SELECT module_key, version FROM modules ORDER BY module_key',
        )->fetchAll('assoc');
        $coreVersion = Application::CORE_VERSION;
        $this->set(compact('history', 'modules', 'coreVersion'));
    }

    public function previewModule()
    {
        $this->request->allowMethod('post');
        $key = (string)$this->request->getData('module_key');
        $path = trim((string)$this->request->getData('source_path'));
        try {
            $preview = (new UpdateManager())->previewModule($key, $path);
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.update.preview_failed', $e->getMessage()));

            return $this->redirect(['action' => 'index']);
        }
        $this->set(compact('preview'));
        $this->set('sourcePath', $path);

        return null;
    }

    public function previewCore()
    {
        $this->request->allowMethod('post');
        $target = trim((string)$this->request->getData('target_version'));
        $force = (bool)$this->request->getData('force');
        try {
            $preview = (new UpdateManager())->previewCore($target);
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.update.preview_failed', $e->getMessage()));

            return $this->redirect(['action' => 'index']);
        }
        $this->set(compact('preview', 'force'));

        return null;
    }

    public function module()
    {
        $this->request->allowMethod('post');
        $key = (string)$this->request->getData('module_key');
        $path = trim((string)$this->request->getData('source_path'));
        try {
            $result = (new UpdateManager())->updateModule($key, $path);
            $this->Flash->success(__('flash.update.module_updated', $key, $result['new_version'] ?? '–'));
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.update.module_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    public function core()
    {
        $this->request->allowMethod('post');
        $target = trim((string)$this->request->getData('target_version'));
        $force = (bool)$this->request->getData('force');
        try {
            (new UpdateManager())->updateCore($target, $force);
            $this->Flash->success(__('flash.update.core_updated', $target));
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.update.core_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
