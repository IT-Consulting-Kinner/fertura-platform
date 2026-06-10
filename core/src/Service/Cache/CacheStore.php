<?php
declare(strict_types=1);

namespace App\Service\Cache;

use Cake\Cache\Cache;
use Throwable;

/**
 * Schmaler, ausfallsicherer Wrapper über `Cake\Cache\Cache` (Programm Tier-3, P02).
 *
 * Vereinheitlicht den Cache-Zugriff für Core und Module und **degradiert
 * gracefully**: ist der konfigurierte Cache nicht verfügbar oder wirft er, wird
 * der Aufruf zur Nicht-Operation (read = miss, write = no-op), sodass der
 * Aufrufer stets korrekt aus der Quelle (DB) weiterarbeitet.
 *
 * Engine/Backend ist über die Cache-Konfiguration (`config/app.php`,
 * `CACHE_*_URL`-Env: file/apcu/redis) steuerbar — Code bleibt unverändert.
 */
class CacheStore
{
    public function __construct(private string $config = '_app_')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // @ unterdrückt umgebungsbedingte FileEngine-Warnungen (z. B. Cache-Pfad
        // nicht beschreibbar) — ein nicht verfügbarer Cache darf NIE eine Anfrage
        // stören (graceful degradation); die Quelle bleibt maßgeblich.
        try {
            $value = @Cache::read($this->key($key), $this->config);

            return $value === null || $value === false ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value): void
    {
        try {
            @Cache::write($this->key($key), $value, $this->config);
        } catch (Throwable) {
            // Cache nicht verfügbar -> ignorieren (Quelle bleibt maßgeblich).
        }
    }

    public function delete(string $key): void
    {
        try {
            @Cache::delete($this->key($key), $this->config);
        } catch (Throwable) {
        }
    }

    public function clear(): void
    {
        try {
            @Cache::clear($this->config);
        } catch (Throwable) {
        }
    }

    /**
     * Liefert den gecachten Wert oder berechnet ihn über `$compute` und legt ihn ab.
     */
    public function remember(string $key, callable $compute): mixed
    {
        try {
            return @Cache::remember($this->key($key), $compute, $this->config);
        } catch (Throwable) {
            return $compute();
        }
    }

    /**
     * Erhöht einen Zähler atomar (sofern die Engine es unterstützt) und legt ihn
     * bei Bedarf an. Gibt den neuen Wert zurück; bei nicht verfügbarem Cache 0
     * (fail-open, z. B. für Rate-Limiting → Verfügbarkeit vor strikter Grenze).
     */
    public function increment(string $key, int $offset = 1): int
    {
        $k = $this->key($key);
        // Bevorzugt atomar (Redis/APCu). FileEngine kann nicht atomar erhöhen
        // (wirft) -> Fallback per read-modify-write (best-effort, nicht atomar;
        // für Rate-Limiting ausreichend, Redis im Mehrinstanzbetrieb empfohlen).
        try {
            $n = @Cache::increment($k, $offset, $this->config);
            if (is_int($n)) {
                return $n;
            }
        } catch (Throwable) {
            // Engine ohne atomares increment -> gesperrter Fallback unten.
        }
        try {
            return $this->lockedRmw($k, static fn (int $cur): int => $cur + $offset);
        } catch (Throwable) {
            return 0; // Cache aus -> fail-open
        }
    }

    /** Verringert einen Zähler (Boden 0); Gegenstück zu {@see increment()}. */
    public function decrement(string $key, int $offset = 1): int
    {
        $k = $this->key($key);
        try {
            $n = @Cache::decrement($k, $offset, $this->config);
            if (is_int($n)) {
                return max(0, $n);
            }
        } catch (Throwable) {
        }
        try {
            return $this->lockedRmw($k, static fn (int $cur): int => max(0, $cur - $offset));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Atomares read-modify-write für den nicht-atomaren Engine-Fallback (FileEngine
     * kann nicht atomar inc/dec). Eine prozessübergreifende Sperrdatei (`flock`)
     * umschließt Lesen+Schreiben, damit nebenläufige inc/dec **kein verlorenes
     * Update** erzeugen — sonst driftet z. B. der SSE-Stream-Zähler nach oben und
     * sperrt einen Benutzer dauerhaft aus (oder nach unten und hebelt das Limit aus).
     * Atomare Engines (Redis/APCu) nehmen diesen Pfad gar nicht erst.
     *
     * @param callable(int):int $mutate
     */
    private function lockedRmw(string $k, callable $mutate): int
    {
        $lockFile = sys_get_temp_dir() . '/fertura_cnt_' . hash('sha256', $this->config . '|' . $k) . '.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            // Sperre nicht möglich -> best-effort ohne Lock (wie zuvor).
            $new = $mutate((int)(@Cache::read($k, $this->config) ?: 0));
            @Cache::write($k, $new, $this->config);

            return $new;
        }
        try {
            @flock($fh, LOCK_EX);
            $new = $mutate((int)(@Cache::read($k, $this->config) ?: 0));
            @Cache::write($k, $new, $this->config);

            return $new;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Normalisiert Schlüssel auf cache-sichere Zeichen. Enthält der Schlüssel
     * unsichere Zeichen (Kollisionsgefahr, z. B. `a:1` vs `a/1` → `a_1`) oder ist
     * er sehr lang, wird ein Hash des Originals angehängt — so bleiben sonst
     * gleich-normalisierte Schlüssel **eindeutig** (wichtig für Rate-Limit-Buckets).
     */
    private function key(string $key): string
    {
        $safe = (string)preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
        if ($safe !== $key || strlen($safe) > 120) {
            return substr($safe, 0, 100) . '_' . substr(hash('sha256', $key), 0, 16);
        }

        return $safe;
    }
}
