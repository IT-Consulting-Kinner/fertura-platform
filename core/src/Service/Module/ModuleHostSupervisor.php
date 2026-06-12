<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use App\Service\Settings\SettingsManager;
use RuntimeException;
use Throwable;

/**
 * Manages the out-of-process module host processes (ch. 23.16.2, phase 2).
 *
 * For each active `out_of_process` module it starts an isolated host
 * (`bin/module-host.php`) with a **sanitized environment** (`env -i`) and the
 * module's **own DB role** (`MODULE_DB_URL`), supervises it and stops it again.
 * Called by the lifecycle (activate/deactivate) and periodically by the worker
 * (self-healing: crashed hosts are restarted). The core invokes the hosts
 * exclusively over their Unix socket ({@see RemoteInvoker}).
 */
class ModuleHostSupervisor
{
    private string $socketDir;
    private string $pidDir;
    private string $logDir;
    private string $tokenDir;

    public function __construct(?string $baseDir = null)
    {
        $base = rtrim($baseDir ?? sys_get_temp_dir(), '/');
        $this->socketDir = $base . '/fertura-mod';
        $this->pidDir = $base . '/fertura-mod-pids';
        $this->logDir = $base . '/fertura-mod-logs';
        $this->tokenDir = $base . '/fertura-mod-tokens';
        foreach ([$this->socketDir, $this->pidDir, $this->logDir, $this->tokenDir] as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0o770, true);
            }
        }
    }

    public function socketPath(string $key): string
    {
        return $this->socketDir . '/' . $key . '.sock';
    }

    private function pidPath(string $key): string
    {
        return $this->pidDir . '/' . $key . '.pid';
    }

    private function tokenPath(string $key): string
    {
        return $this->tokenDir . '/' . $key . '.token';
    }

    private function hostScript(): string
    {
        return ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'module-host.php';
    }

    /**
     * Optional launcher prefix (`core.module.host.launcher`) for additional OS
     * isolation of the hosts (dedicated user/sandbox). Empty = no prefix.
     */
    private function launcherPrefix(): string
    {
        try {
            return (string)(new SettingsManager())
                ->get('core', 'module.host.launcher', '');
        } catch (Throwable) {
            return ''; // settings unavailable (e.g. early boot) -> no prefix
        }
    }

    public function isRunning(string $key): bool
    {
        $sock = @stream_socket_client('unix://' . $this->socketPath($key), $errno, $errstr, 1);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    /**
     * Starts the host for a module with a sanitized environment + its own DB
     * role. Idempotent: if it is already running, nothing happens.
     */
    public function spawn(string $key): void
    {
        // Serialize per key (against check->spawn races that would otherwise
        // produce duplicate or orphaned hosts).
        $lock = fopen($this->pidDir . '/' . $key . '.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return; // another spawn is already in progress
        }
        try {
            if ($this->isRunning($key)) {
                return;
            }
            $mod = Db::privileged()->execute(
                "SELECT source_path, php_namespace FROM modules WHERE module_key = :k AND status = 'active' AND isolation = 'out_of_process'",
                ['k' => $key],
            )->fetch('assoc');
            if ($mod === false) {
                throw new RuntimeException("Kein aktives out_of_process-Modul: $key");
            }
            // CakePHP connection URL of the module role for the ConnectionManager
            // configuration in the host (phase 3: contribution classes use the
            // ORM connection, not just raw PDO).
            $dsn = (new ModuleDbRole())->cakeUrl($key);
            if ($dsn === null) {
                throw new RuntimeException("Keine DB-Rolle provisioniert für: $key");
            }

            // RPC secret (the HMAC key for the per-call tokens): written only to
            // the 0600 file and passed to the host as a **path** — the value
            // itself does NOT end up in the process environment
            // (`/proc/<pid>/environ`) or on the command line (ch. 23.16.2). Core
            // and host read it from the (owner-only) file.
            $token = bin2hex(random_bytes(32));
            @file_put_contents($this->tokenPath($key), $token);
            @chmod($this->tokenPath($key), 0o600);

            // Only these variables are visible in the isolated process (env -i):
            // NO core DATABASE_URL, NO BACKUP_PASSWORD, NO plaintext secret.
            $env = [
                'PATH' => (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'MODULE_KEY' => $key,
                'MODULE_SOCKET' => $this->socketPath($key),
                'MODULE_SRC' => rtrim((string)$mod['source_path'], '/') . '/src',
                'MODULE_NAMESPACE' => (string)$mod['php_namespace'],
                'MODULE_MANIFEST' => rtrim((string)$mod['source_path'], '/') . '/manifest.json',
                'MODULE_DB_URL' => $dsn,
                'MODULE_RPC_TOKEN_FILE' => $this->tokenPath($key),
            ];
            $assign = '';
            foreach ($env as $k => $v) {
                $assign .= $k . '=' . escapeshellarg($v) . ' ';
            }
            $log = $this->logDir . '/' . $key . '.log';
            // Optional launcher prefix (ch. 23.16.2): additional OS isolation
            // (dedicated user via setpriv/sudo, FS/kernel sandbox via bwrap/
            // firejail, …) without changing core code. Placed between
            // `env -i <vars>` and `php`, so it runs in the sanitized environment
            // and wraps/exec's `php`. Empty = no prefix (default).
            $launcher = trim((string)$this->launcherPrefix());
            $prefix = $launcher === '' ? '' : $launcher . ' ';
            // Sanitized environment (env -i), detached (nohup &), capture the PID.
            $cmd = 'nohup env -i ' . $assign . $prefix . 'php ' . escapeshellarg($this->hostScript())
                . ' ' . escapeshellarg($key) . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $pid = trim((string)shell_exec('/bin/sh -c ' . escapeshellarg($cmd)));
            if ($pid !== '') {
                file_put_contents($this->pidPath($key), $pid);
            }

            // Wait for the socket to become ready (max ~3 s).
            for ($i = 0; $i < 30 && !$this->isRunning($key); $i++) {
                usleep(100_000);
            }
            // Fail loudly if the host did not come up (e.g. php/shell_exec
            // unavailable) — otherwise the caller would assume all is well.
            if (!$this->isRunning($key)) {
                throw new RuntimeException("Modul-Host für $key nicht gestartet (siehe $log).");
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Stops the host (SIGTERM -> clean shutdown) and cleans up. */
    public function stop(string $key): void
    {
        // Find and terminate the **actual** host process by its command line
        // (`module-host.php` + key) — robust even when a forking launcher
        // (firejail and the like) has "distorted" the captured `$!` PID (otherwise
        // the real host would be left orphaned). The double condition (script AND
        // key) is safe against PID recycling.
        $pids = $this->findHostPids($key);

        // Also consider the captured PID (exec-style launchers).
        $pidFile = $this->pidPath($key);
        if (is_file($pidFile)) {
            $stored = (int)trim((string)file_get_contents($pidFile));
            if ($stored > 0 && $this->isOurHost($stored, $key) && !in_array($stored, $pids, true)) {
                $pids[] = $stored;
            }
            @unlink($pidFile);
        }
        foreach ($pids as $pid) {
            @shell_exec('kill -TERM ' . (int)$pid . ' 2>/dev/null');
        }
        @unlink($this->socketPath($key));
        @unlink($this->tokenPath($key));
    }

    /**
     * Finds the PID(s) of the running module host for this key via `/proc` (the
     * command line contains `module-host.php` AND the key as its own token).
     * Finds the **real** `php` host even behind a forking launcher.
     *
     * @return list<int>
     */
    private function findHostPids(string $key): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = @file_get_contents($file);
            if ($cmdline === false || $cmdline === '') {
                continue;
            }
            $args = explode("\0", $cmdline);
            if (str_contains(implode(' ', $args), 'module-host.php') && in_array($key, $args, true)) {
                $pids[] = (int)basename(dirname($file));
            }
        }

        return $pids;
    }

    /**
     * Checks whether $pid really is the module host for this key (PID-recycling
     * protection).
     *
     * Wrapper-tolerant: a launcher prefix (setpriv/bwrap/firejail/…) appears in
     * `/proc/<pid>/cmdline` before `php module-host.php <key>` and may carry the
     * arguments either individually (exec-style wrappers) or as one combined
     * string (`sh -c "…"`). We therefore check for the **presence** of the host
     * script AND the module key in the assembled command line rather than an
     * exact argv token — both together are practically impossible for an
     * unrelated, recycled PID, so this stays recycling-safe.
     */
    private function isOurHost(int $pid, string $key): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false || $cmdline === '') {
            return false; // process gone / unreadable -> do not kill
        }
        $joined = str_replace("\0", ' ', $cmdline);

        return str_contains($joined, 'module-host.php') && str_contains($joined, $key);
    }

    /** Starts the host if the module is active+isolated and not running. */
    public function ensureRunning(string $key): void
    {
        if (!$this->isRunning($key)) {
            $this->spawn($key);
        }
    }

    /**
     * Ensures a host is running for ALL active out_of_process modules (worker
     * self-healing). Returns the (newly) started keys.
     *
     * @return list<string>
     */
    public function ensureAll(): array
    {
        $rows = Db::privileged()->execute(
            "SELECT module_key FROM modules WHERE status = 'active' AND isolation = 'out_of_process'",
        )->fetchAll('assoc');
        $started = [];
        foreach ($rows as $r) {
            $key = (string)$r['module_key'];
            if (!$this->isRunning($key)) {
                try {
                    $this->spawn($key);
                    $started[] = $key;
                } catch (Throwable) {
                    // best effort; the next tick retries
                }
            }
        }

        return $started;
    }

    /**
     * Stops hosts whose module is no longer active+isolated (cleanup).
     *
     * @return list<string>
     */
    public function reapStale(): array
    {
        $active = [];
        foreach (
            Db::privileged()->execute(
                "SELECT module_key FROM modules WHERE status = 'active' AND isolation = 'out_of_process'",
            )->fetchAll('assoc') as $r
        ) {
            $active[(string)$r['module_key']] = true;
        }
        $stopped = [];
        foreach (glob($this->pidDir . '/*.pid') ?: [] as $f) {
            $key = basename($f, '.pid');
            if (!isset($active[$key])) {
                $this->stop($key);
                $stopped[] = $key;
            }
        }

        return $stopped;
    }

    /** @return list<array{key:string,running:bool}> */
    public function status(): array
    {
        $rows = Db::privileged()->execute(
            "SELECT module_key FROM modules WHERE status = 'active' AND isolation = 'out_of_process' ORDER BY module_key",
        )->fetchAll('assoc');
        $out = [];
        foreach ($rows as $r) {
            $key = (string)$r['module_key'];
            $out[] = ['key' => $key, 'running' => $this->isRunning($key)];
        }

        return $out;
    }
}
