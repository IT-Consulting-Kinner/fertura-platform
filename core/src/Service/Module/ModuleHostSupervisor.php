<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use Throwable;

/**
 * Verwaltet die Out-of-Process-Modulhost-Prozesse (Kap. 23.16.2, Phase 2).
 *
 * Startet je aktivem `out_of_process`-Modul einen isolierten Host
 * (`bin/module-host.php`) mit **bereinigter Umgebung** (`env -i`) und der
 * **eigenen DB-Rolle** (`MODULE_DB_URL`), überwacht ihn und stoppt ihn wieder.
 * Aufgerufen vom Lifecycle (aktivieren/deaktivieren) und periodisch vom Worker
 * (Selbstheilung: abgestürzte Hosts werden neu gestartet). Der Core ruft die
 * Hosts ausschließlich über ihren Unix-Socket auf ({@see RemoteInvoker}).
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
     * Optionales Launcher-Prefix (`core.module.host.launcher`) für zusätzliche
     * OS-Isolation der Hosts (eigener Benutzer/Sandbox). Leer = kein Prefix.
     */
    private function launcherPrefix(): string
    {
        try {
            return (string)(new \App\Service\Settings\SettingsManager())
                ->get('core', 'module.host.launcher', '');
        } catch (Throwable) {
            return ''; // Settings nicht verfügbar (z. B. früher Boot) -> kein Prefix
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
     * Startet den Host für ein Modul mit bereinigter Umgebung + eigener DB-Rolle.
     * Idempotent: läuft er bereits, passiert nichts.
     */
    public function spawn(string $key): void
    {
        // Pro Key serialisieren (gegen Check->Spawn-Races, die doppelte oder
        // verwaiste Hosts erzeugen würden).
        $lock = fopen($this->pidDir . '/' . $key . '.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return; // ein anderer Spawn läuft bereits
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
                throw new \RuntimeException("Kein aktives out_of_process-Modul: $key");
            }
            // CakePHP-Connection-URL der Modul-Rolle für die ConnectionManager-
            // Konfiguration im Host (Phase 3: Beitragsklassen nutzen die ORM-
            // Connection, nicht nur rohes PDO).
            $dsn = (new ModuleDbRole())->cakeUrl($key);
            if ($dsn === null) {
                throw new \RuntimeException("Keine DB-Rolle provisioniert für: $key");
            }

            // RPC-Token: nur wer es kennt (der Core über die 0600-Datei) darf den
            // Host aufrufen -> Socket ist nicht mehr anonym ansprechbar (Kap. 23.16.2).
            $token = bin2hex(random_bytes(32));
            @file_put_contents($this->tokenPath($key), $token);
            @chmod($this->tokenPath($key), 0o600);

            // Nur diese Variablen sind im isolierten Prozess sichtbar (env -i):
            // KEIN Core-DATABASE_URL, KEIN BACKUP_PASSWORD.
            $env = [
                'PATH' => (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'MODULE_KEY' => $key,
                'MODULE_SOCKET' => $this->socketPath($key),
                'MODULE_SRC' => rtrim((string)$mod['source_path'], '/') . '/src',
                'MODULE_NAMESPACE' => (string)$mod['php_namespace'],
                'MODULE_MANIFEST' => rtrim((string)$mod['source_path'], '/') . '/manifest.json',
                'MODULE_DB_URL' => $dsn,
                'MODULE_RPC_TOKEN' => $token,
            ];
            $assign = '';
            foreach ($env as $k => $v) {
                $assign .= $k . '=' . escapeshellarg($v) . ' ';
            }
            $log = $this->logDir . '/' . $key . '.log';
            // Optionales Launcher-Prefix (Kap. 23.16.2): zusätzliche OS-Isolation
            // (eigener Benutzer via setpriv/sudo, FS/Kernel-Sandbox via bwrap/
            // firejail, …) ohne Core-Codeänderung. Wird zwischen `env -i <vars>`
            // und `php` gesetzt, läuft also in der bereinigten Umgebung und
            // wrapt/exec't `php`. Leer = kein Prefix (Default).
            $launcher = trim((string)$this->launcherPrefix());
            $prefix = $launcher === '' ? '' : $launcher . ' ';
            // Bereinigte Umgebung (env -i), losgelöst (nohup &), PID einfangen.
            $cmd = 'nohup env -i ' . $assign . $prefix . 'php ' . escapeshellarg($this->hostScript())
                . ' ' . escapeshellarg($key) . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $pid = trim((string)shell_exec('/bin/sh -c ' . escapeshellarg($cmd)));
            if ($pid !== '') {
                file_put_contents($this->pidPath($key), $pid);
            }

            // Auf Socket-Bereitschaft warten (max ~3 s).
            for ($i = 0; $i < 30 && !$this->isRunning($key); $i++) {
                usleep(100_000);
            }
            // Laut scheitern, wenn der Host nicht hochkam (z. B. php/shell_exec
            // nicht verfügbar) — sonst glaubt der Aufrufer, alles sei in Ordnung.
            if (!$this->isRunning($key)) {
                throw new \RuntimeException("Modul-Host für $key nicht gestartet (siehe $log).");
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Stoppt den Host (SIGTERM -> sauberes Herunterfahren) und räumt auf. */
    public function stop(string $key): void
    {
        $pidFile = $this->pidPath($key);
        if (is_file($pidFile)) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            // Nur killen, wenn die PID wirklich noch unser Host ist (gegen
            // PID-Recycling: sonst träfe SIGTERM einen Fremdprozess).
            if ($pid > 0 && $this->isOurHost($pid, $key)) {
                @shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
            }
            @unlink($pidFile);
        }
        @unlink($this->socketPath($key));
        @unlink($this->tokenPath($key));
    }

    /**
     * Prüft, ob $pid tatsächlich der Modul-Host dieses Keys ist (PID-Recycling-Schutz).
     *
     * Wrapper-tolerant: ein Launcher-Prefix (setpriv/bwrap/firejail/…) erscheint
     * in der `/proc/<pid>/cmdline` vor `php module-host.php <key>` und kann die
     * Argumente entweder einzeln (exec-artige Wrapper) oder als kombinierten
     * String (`sh -c "…"`) führen. Wir prüfen daher auf das **Vorkommen** von
     * Host-Skript UND Modul-Key in der zusammengesetzten Kommandozeile statt auf
     * ein exaktes argv-Token — beides zusammen ist für einen fremden, recycelten
     * PID praktisch unmöglich, bleibt also recycling-sicher.
     */
    private function isOurHost(int $pid, string $key): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false || $cmdline === '') {
            return false; // Prozess weg / nicht lesbar -> nicht killen
        }
        $joined = str_replace("\0", ' ', $cmdline);

        return str_contains($joined, 'module-host.php') && str_contains($joined, $key);
    }

    /** Startet den Host, falls das Modul aktiv+isoliert ist und nicht läuft. */
    public function ensureRunning(string $key): void
    {
        if (!$this->isRunning($key)) {
            $this->spawn($key);
        }
    }

    /**
     * Stellt sicher, dass für ALLE aktiven out_of_process-Module ein Host läuft
     * (Worker-Selbstheilung). Gibt die (neu) gestarteten Schlüssel zurück.
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
                    // best effort; nächster Tick versucht es erneut
                }
            }
        }

        return $started;
    }

    /**
     * Stoppt Hosts, deren Modul nicht mehr aktiv+isoliert ist (Aufräumen).
     *
     * @return list<string>
     */
    public function reapStale(): array
    {
        $active = [];
        foreach (Db::privileged()->execute(
            "SELECT module_key FROM modules WHERE status = 'active' AND isolation = 'out_of_process'",
        )->fetchAll('assoc') as $r) {
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
