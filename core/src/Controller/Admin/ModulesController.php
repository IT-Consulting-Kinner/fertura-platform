<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Module\ModuleInstallJobService;
use App\Service\Module\ModuleLifecycle;
use App\Service\System\MaintenanceService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Throwable;

/**
 * Module lifecycle (admin area "Module Lifecycle").
 *
 * Installs a **signed** module package uploaded via the GUI as an async job (the
 * worker extracts + installs; the page polls the status); also controls
 * activation/deactivation/removal and displays dependencies.
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
        // Active install job (if any) -> status banner + polling in the view.
        $this->set('installJob', (new ModuleInstallJobService())->active());
        $this->set(compact('modules', 'deps'));
    }

    /**
     * Receives an uploaded **signed** module package (.zip), stores it and enqueues
     * an async install job (the worker extracts + installs; signature is verified
     * there). Returns immediately — the page polls {@see installStatus()}.
     */
    public function install(): ?Response
    {
        $this->request->allowMethod('post');
        // During maintenance the worker is paused, so a standalone install would sit
        // un-drained and wedge the protected drain. Route the operator to the
        // protected install (backup → install → verify) instead.
        if ((new MaintenanceService())->isActive()) {
            $this->Flash->error(__('flash.module.use_protected_install'));

            return $this->redirect(['action' => 'index']);
        }
        $file = $this->request->getUploadedFile('package');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('flash.module.no_package'));

            return $this->redirect(['action' => 'index']);
        }
        $name = (string)$file->getClientFilename();
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            $this->Flash->error(__('flash.module.not_zip'));

            return $this->redirect(['action' => 'index']);
        }

        // Stored under the shared ./core mount so the worker can read it.
        $dir = TMP . 'module_uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        $file->moveTo($path);

        $actor = $this->identity()?->getIdentifier();
        (new ModuleInstallJobService())->enqueue($path, $name, is_scalar($actor) ? (string)$actor : null);
        $this->Flash->success(__('flash.module.install_queued'));

        return $this->redirect(['action' => 'index']);
    }

    /** JSON status of an install job (polled by the index page). */
    public function installStatus(): Response
    {
        $id = (string)$this->request->getQuery('id');
        $job = $id !== '' ? (new ModuleInstallJobService())->get($id) : null;
        $payload = $job === null
            ? ['found' => false]
            : [
                'found' => true,
                'status' => (string)$job['status'],
                'done' => in_array($job['status'], ['succeeded', 'failed'], true),
                'module_key' => $job['module_key'],
                'message' => $job['message'],
            ];

        return $this->response->withType('application/json')->withStringBody((string)json_encode($payload));
    }

    /**
     * Graphical dependency view (ch. 23.13.1): layered SVG
     * (layer = depth of the dependency chain), edges run module → dependency.
     * Computed server-side (no client-side JS needed).
     */
    public function graph(): void
    {
        $conn = ConnectionManager::get('default');
        $mods = $conn->execute(
            'SELECT module_key, name, version, status FROM modules ORDER BY module_key',
        )->fetchAll('assoc');
        $depRows = $conn->execute(
            'SELECT m.module_key AS module, d.required_module_key AS requires '
            . 'FROM module_dependencies d JOIN modules m ON m.id = d.module_id',
        )->fetchAll('assoc');

        $keys = array_column($mods, 'module_key');
        $level = array_fill_keys($keys, 0);
        $reqsByMod = [];
        foreach ($depRows as $d) {
            $reqsByMod[(string)$d['module']][] = (string)$d['requires'];
        }
        // Longest-path relaxation (modules form an acyclic graph, guaranteed by the lifecycle).
        for ($i = 0, $c = count($keys); $i <= $c; $i++) {
            foreach ($reqsByMod as $m => $reqs) {
                foreach ($reqs as $r) {
                    if (isset($level[$m], $level[$r])) {
                        $level[$m] = max($level[$m], $level[$r] + 1);
                    }
                }
            }
        }

        // Positions per layer.
        $boxW = 170;
        $boxH = 46;
        $colGap = 90;
        $rowGap = 22;
        $pad = 20;
        $byLevel = [];
        foreach ($keys as $k) {
            $byLevel[$level[$k]][] = $k;
        }
        ksort($byLevel);
        $pos = [];
        $maxRows = 0;
        foreach ($byLevel as $lvl => $ks) {
            sort($ks);
            foreach ($ks as $idx => $k) {
                $pos[$k] = [
                    'x' => $pad + $lvl * ($boxW + $colGap),
                    'y' => $pad + $idx * ($boxH + $rowGap),
                ];
            }
            $maxRows = max($maxRows, count($ks));
        }
        $byKey = [];
        foreach ($mods as $m) {
            $byKey[(string)$m['module_key']] = $m;
        }

        $nodes = [];
        foreach ($keys as $k) {
            $nodes[] = [
                'key' => $k,
                'name' => (string)($byKey[$k]['name'] ?? $k),
                'version' => (string)($byKey[$k]['version'] ?? ''),
                'status' => (string)($byKey[$k]['status'] ?? ''),
                'x' => $pos[$k]['x'], 'y' => $pos[$k]['y'], 'w' => $boxW, 'h' => $boxH,
            ];
        }
        $edges = [];
        foreach ($reqsByMod as $m => $reqs) {
            foreach ($reqs as $r) {
                if (!isset($pos[$m], $pos[$r])) {
                    continue;
                }
                $edges[] = [
                    'x1' => $pos[$m]['x'], 'y1' => $pos[$m]['y'] + $boxH / 2,
                    'x2' => $pos[$r]['x'] + $boxW, 'y2' => $pos[$r]['y'] + $boxH / 2,
                ];
            }
        }
        $width = $pad * 2 + (count($byLevel) ?: 1) * ($boxW + $colGap);
        $height = $pad * 2 + max(1, $maxRows) * ($boxH + $rowGap);

        $this->set(compact('nodes', 'edges', 'width', 'height'));
    }

    public function activate(string $key): ?Response
    {
        $this->request->allowMethod('post');

        return $this->run(fn(ModuleLifecycle $l) => $l->activate($key), __('flash.module.activated'));
    }

    public function deactivate(string $key): ?Response
    {
        $this->request->allowMethod('post');

        return $this->run(function (ModuleLifecycle $l) use ($key): void {
            $l->deactivate($key);
        }, __('flash.module.deactivated'));
    }

    public function delete(string $key): ?Response
    {
        $this->request->allowMethod('post');

        return $this->run(function (ModuleLifecycle $l) use ($key): void {
            $l->delete($key);
        }, __('flash.module.deleted'));
    }

    private function run(callable $fn, string $okMessage): ?Response
    {
        try {
            $fn(new ModuleLifecycle());
            $this->Flash->success($okMessage);
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.module.failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
