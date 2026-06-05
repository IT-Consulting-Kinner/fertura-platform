<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Update\UpdateManager;
use Cake\Datasource\ConnectionManager;

/**
 * Update-Manager (Administrationsbereich „Update-Manager").
 *
 * Zeigt die Update-Historie und erlaubt das Auslösen von Modul-/Core-Updates
 * aus einem bereitgestellten (signierten) Paketpfad. Bei Fehlern wird gemäß
 * Step 8 ein Down-Migrations-Rollback gefahren.
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
        $coreVersion = \App\Application::CORE_VERSION;
        $this->set(compact('history', 'modules', 'coreVersion'));
    }

    public function previewModule()
    {
        $this->request->allowMethod('post');
        $key = (string)$this->request->getData('module_key');
        $path = trim((string)$this->request->getData('source_path'));
        try {
            $preview = (new UpdateManager())->previewModule($key, $path);
        } catch (\Throwable $e) {
            $this->Flash->error('Vorschau fehlgeschlagen: ' . $e->getMessage());

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
        } catch (\Throwable $e) {
            $this->Flash->error('Vorschau fehlgeschlagen: ' . $e->getMessage());

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
            $this->Flash->success(sprintf('Modul %s aktualisiert: %s', $key, $result['new_version'] ?? '–'));
        } catch (\Throwable $e) {
            $this->Flash->error('Update fehlgeschlagen (Rollback ausgeführt): ' . $e->getMessage());
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
            $this->Flash->success('Core aktualisiert auf ' . $target . '.');
        } catch (\Throwable $e) {
            $this->Flash->error('Core-Update fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->redirect(['action' => 'index']);
    }
}
