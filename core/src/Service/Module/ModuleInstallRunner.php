<?php
declare(strict_types=1);

namespace App\Service\Module;

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
        $workDir = null;
        try {
            $workDir = $this->extract((string)$job['package_path']);
            $sourceDir = $this->locateManifestDir($workDir);
            $mod = $this->lifecycle->install($sourceDir, (string)$job['isolation']);
            $this->jobs->markSucceeded($id, (string)$mod['module_key']);
        } catch (Throwable $e) {
            $this->jobs->markFailed($id, $e->getMessage());
        } finally {
            $this->cleanup($workDir, (string)$job['package_path']);
        }

        return $id;
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

    private function cleanup(?string $workDir, string $zipPath): void
    {
        if ($workDir !== null && is_dir($workDir)) {
            $this->rrmdir($workDir);
        }
        @unlink($zipPath);
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
