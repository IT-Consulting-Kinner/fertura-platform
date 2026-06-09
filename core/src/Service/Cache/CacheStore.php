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

    /** Normalisiert Schlüssel auf cache-sichere Zeichen. */
    private function key(string $key): string
    {
        return (string)preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }
}
