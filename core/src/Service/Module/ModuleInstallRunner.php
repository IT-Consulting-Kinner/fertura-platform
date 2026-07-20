<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Service\Update\UpdateManager;
use Cake\Datasource\ConnectionManager;
use Throwable;
use ZipArchive;

/**
 * Worker-side processor for queued GUI module-install jobs. Called once per worker
 * cycle (error-isolated, like the scheduled-task runner): claims one queued job,
 * extracts the uploaded signed .zip, runs the (DDL-heavy) install via
 * {@see ModuleLifecycle} (which verifies the package signature), writes the result
 * back, and cleans up the temp files. The web request never blocks on the install.
 */
class ModuleInstallRunner
{
    public function __construct(
        private ModuleInstallJobService $jobs = new ModuleInstallJobService(),
        private ModuleLifecycle $lifecycle = new ModuleLifecycle(),
        private UpdateManager $updates = new UpdateManager(),
    ) {
    }

    /**
     * Processes at most one queued job. Returns the processed job id, or null if
     * the queue was empty (so the worker loop can decide whether anything happened).
     */
    public function tick(): ?string
    {
        $job = $this->jobs->claimNext();
        if ($job === null) {
            return null;
        }
        $id = (string)$job['id'];
        try {
            $mod = $this->installPackage((string)$job['package_path']);
            $this->jobs->markSucceeded($id, (string)$mod['module_key']);
        } catch (Throwable $e) {
            $this->jobs->markFailed($id, $e->getMessage());
        } finally {
            @unlink((string)$job['package_path']);
        }

        return $id;
    }

    /**
     * Extracts a signed package zip and runs the DDL-heavy lifecycle install. Shared
     * by the standalone worker job ({@see tick()}) and the maintenance critical-action
     * handler ({@see \App\Service\System\ModuleInstallHandler}). Removes only the work
     * dir — the caller owns the source zip's lifecycle.
     *
     * @return array<string, mixed> the installed module row
     */
    public function installPackage(string $packagePath): array
    {
        $workDir = null;
        try {
            $workDir = $this->extract($packagePath);
            $sourceDir = $this->locateManifestDir($workDir);

            // Install-or-UPDATE: if the package's module is already installed, take
            // the update path (migrations + rollback-on-failure) instead of a fresh
            // install — so an operator can UPDATE a module by uploading its new
            // package, the same upload UX as install (ch. 24.13). Both paths verify
            // the package signature; updateModule rejects a same/older version. The
            // source-path + preview flow on the Update-Manager page stays for the
            // deliberate, previewed update. An unknown module key -> fresh install.
            $key = $this->manifestKey($sourceDir);
            if ($key !== '' && $this->moduleExists($key)) {
                return $this->updates->updateModule($key, $sourceDir);
            }

            return $this->lifecycle->install($sourceDir);
        } finally {
            if ($workDir !== null && is_dir($workDir)) {
                $this->rrmdir($workDir);
            }
        }
    }

    /** The package manifest's module key, or '' when unreadable/invalid. */
    private function manifestKey(string $sourceDir): string
    {
        $json = @file_get_contents($sourceDir . '/manifest.json');
        if ($json === false) {
            return '';
        }
        try {
            return ModuleManifest::fromJson($json)->key();
        } catch (Throwable) {
            return '';
        }
    }

    /** Whether a module with this key is already installed. */
    private function moduleExists(string $key): bool
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn->execute(
            'SELECT 1 FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch() !== false;
    }

    /** Extracts the .zip into a fresh working directory (zip-slip guarded). */
    private function extract(string $zipPath): string
    {
        if (!is_file($zipPath)) {
            throw new LifecycleException('Hochgeladenes Paket nicht gefunden.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new LifecycleException('Paket ist kein gültiges ZIP-Archiv.');
        }
        // Refuse entries that escape the target dir (zip-slip) before extracting.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_starts_with($name, '/') || str_contains($name, '..')) {
                $zip->close();
                throw new LifecycleException('Unsicherer Pfad im Archiv: ' . $name);
            }
        }
        $dir = dirname($zipPath) . DIRECTORY_SEPARATOR . 'x_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0o775, true);
        $ok = $zip->extractTo($dir);
        $zip->close();
        if ($ok !== true) {
            throw new LifecycleException('Paket konnte nicht entpackt werden.');
        }

        return $dir;
    }

    /**
     * Finds the directory holding manifest.json — either the zip root or a single
     * top-level folder (a package zipped "with its folder").
     */
    private function locateManifestDir(string $dir): string
    {
        if (is_file($dir . '/manifest.json')) {
            return $dir;
        }
        $subDirs = array_values(array_filter(glob($dir . '/*') ?: [], 'is_dir'));
        if (count($subDirs) === 1 && is_file($subDirs[0] . '/manifest.json')) {
            return $subDirs[0];
        }
        throw new LifecycleException('manifest.json im Paket nicht gefunden.');
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
