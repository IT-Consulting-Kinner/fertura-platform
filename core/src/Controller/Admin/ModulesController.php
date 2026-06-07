<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Module\ModuleLifecycle;
use Cake\Datasource\ConnectionManager;

/**
 * Modul-Lebenszyklus (Administrationsbereich „Modul-Lifecycle").
 *
 * Installation erfolgt über die CLI (signierte Pakete); die GUI steuert
 * Aktivierung/Deaktivierung/Entfernung und zeigt Abhängigkeiten.
 */
class ModulesController extends AdminController
{
    protected ?string $requiredArea = 'module_lifecycle';

    public function index(): void
    {
        $conn = ConnectionManager::get('default');
        $modules = $conn->execute(
            'SELECT m.module_key, m.name, m.version, m.type, m.status, m.requires_license, '
            . 'm.signature_status, '
            . '(SELECT count(*) FROM module_dependencies d WHERE d.module_id = m.id) AS dep_count '
            . 'FROM modules m ORDER BY m.module_key',
        )->fetchAll('assoc');
        $deps = $conn->execute(
            'SELECT m.module_key AS module, d.required_module_key AS requires, d.required_version '
            . 'FROM module_dependencies d JOIN modules m ON m.id = d.module_id ORDER BY m.module_key',
        )->fetchAll('assoc');
        $this->set(compact('modules', 'deps'));
    }

    public function activate(string $key)
    {
        $this->request->allowMethod('post');

        return $this->run(fn (ModuleLifecycle $l) => $l->activate($key), __('flash.module.activated'));
    }

    public function deactivate(string $key)
    {
        $this->request->allowMethod('post');

        return $this->run(function (ModuleLifecycle $l) use ($key): void {
            $l->deactivate($key);
        }, __('flash.module.deactivated'));
    }

    public function delete(string $key)
    {
        $this->request->allowMethod('post');

        return $this->run(function (ModuleLifecycle $l) use ($key): void {
            $l->delete($key);
        }, __('flash.module.deleted'));
    }

    private function run(callable $fn, string $okMessage)
    {
        try {
            $fn(new ModuleLifecycle());
            $this->Flash->success($okMessage);
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.module.failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
