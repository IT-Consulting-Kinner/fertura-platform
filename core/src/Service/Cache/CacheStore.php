<?php
declare(strict_types=1);

namespace App\Service\Cache;

use Cake\Cache\Cache;
use Throwable;

/**
 * Thin, fail-safe wrapper around `Cake\Cache\Cache` (program tier-3, P02).
 *
 * Unifies cache access for core and modules and **degrades gracefully**: if the
 * configured cache is unavailable or throws, the call becomes a no-operation
 * (read = miss, write = no-op), so the caller always continues correctly from
 * the source (DB).
 *
 * Engine/backend is configurable via the cache configuration (`config/app.php`,
 * `CACHE_*_URL` env: file/apcu/redis) — the code stays unchanged.
 */
class CacheStore
{
    public function __construct(private string $config = '_app_')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // @ suppresses environment-dependent FileEngine warnings (e.g. cache path
        // not writable) — an unavailable cache must NEVER disrupt a request
        // (graceful degradation); the source remains authoritative.
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
            // Cache unavailable -> ignore (the source remains authoritative).
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
     * Returns the cached value, or computes it via `$compute` and stores it.
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
     * Increments a counter atomically (where the engine supports it) and creates
     * it if needed. Returns the new value; 0 if the cache is unavailable
     * (fail-open, e.g. for rate limiting → availability over a strict limit).
     */
    public function increment(string $key, int $offset = 1): int
    {
        $k = $this->key($key);
        // Prefers atomic (Redis/APCu). FileEngine cannot increment atomically
        // (throws) -> fallback via read-modify-write (best-effort, not atomic;
        // sufficient for rate limiting, Redis recommended for multi-instance setups).
        try {
            $n = @Cache::increment($k, $offset, $this->config);
            if (is_int($n)) {
                return $n;
            }
        } catch (Throwable) {
            // Engine without atomic increment -> locked fallback below.
        }
        try {
            return $this->lockedRmw($k, static fn (int $cur): int => $cur + $offset);
        } catch (Throwable) {
            return 0; // cache off -> fail-open
        }
    }

    /** Decrements a counter (floored at 0); counterpart to {@see increment()}. */
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
     * Atomic read-modify-write for the non-atomic engine fallback (FileEngine
     * cannot inc/dec atomically). A cross-process lock file (`flock`) wraps
     * read+write so concurrent inc/dec produce **no lost update** — otherwise the
     * SSE stream counter, for example, drifts upward and locks a user out
     * permanently (or downward and defeats the limit). Atomic engines
     * (Redis/APCu) never take this path.
     *
     * @param callable(int):int $mutate
     */
    private function lockedRmw(string $k, callable $mutate): int
    {
        $lockFile = sys_get_temp_dir() . '/fertura_cnt_' . hash('sha256', $this->config . '|' . $k) . '.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            // Lock not possible -> best-effort without a lock (as before).
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
     * Normalizes keys to cache-safe characters. If the key contains unsafe
     * characters (collision risk, e.g. `a:1` vs `a/1` → `a_1`) or is very long, a
     * hash of the original is appended — so otherwise-identically-normalized keys
     * stay **unique** (important for rate-limit buckets).
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
