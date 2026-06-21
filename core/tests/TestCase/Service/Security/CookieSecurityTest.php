<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Security;

use App\Service\Security\CookieSecurity;
use Cake\TestSuite\TestCase;

/**
 * Pins the fail-safe Secure decision for the whole cookie family: ON outside
 * local debug/dev, OFF in debug, and an explicit SESSION_COOKIE_SECURE wins
 * either way. {@see \App\Service\Security\CookieSecurity}.
 */
class CookieSecurityTest extends TestCase
{
    /**
     * @var array<string, string|false> Snapshot of getenv() before the test.
     */
    private array $envBackup = [];

    /**
     * @var array<string, array{server: bool, serverVal: mixed, env: bool, envVal: mixed}>
     */
    private array $superglobalBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([CookieSecurity::ENV_OVERRIDE, 'DEBUG'] as $key) {
            $this->envBackup[$key] = getenv($key);
            $this->superglobalBackup[$key] = [
                'server' => array_key_exists($key, $_SERVER),
                'serverVal' => $_SERVER[$key] ?? null,
                'env' => array_key_exists($key, $_ENV),
                'envVal' => $_ENV[$key] ?? null,
            ];
        }
    }

    protected function tearDown(): void
    {
        // Restore exactly — env() reads $_SERVER, then $_ENV, then getenv(), so all
        // three layers must return to their original state to avoid leaking debug=0
        // (which would flip Secure on) into unrelated tests.
        foreach ([CookieSecurity::ENV_OVERRIDE, 'DEBUG'] as $key) {
            $backup = $this->superglobalBackup[$key];
            if ($backup['server']) {
                $_SERVER[$key] = $backup['serverVal'];
            } else {
                unset($_SERVER[$key]);
            }
            if ($backup['env']) {
                $_ENV[$key] = $backup['envVal'];
            } else {
                unset($_ENV[$key]);
            }
            if ($this->envBackup[$key] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $this->envBackup[$key]);
            }
        }
        parent::tearDown();
    }

    /** Set or (with null) unset a var across all three layers env() consults. */
    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    public function testProductionFailsSafeSecureWhenDebugOff(): void
    {
        // The core of the hardening: no explicit pin + non-debug deploy -> Secure ON,
        // even though SESSION_COOKIE_SECURE was never set in the environment.
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, null);
        $this->setEnv('DEBUG', '0');
        $this->assertTrue(CookieSecurity::enabled());
    }

    public function testLocalDevDropsSecureWhenDebugOn(): void
    {
        // No explicit pin + debug -> Secure OFF so local HTTP dev keeps working.
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, null);
        $this->setEnv('DEBUG', 'true');
        $this->assertFalse(CookieSecurity::enabled());
    }

    public function testExplicitOnWinsEvenInDebug(): void
    {
        $this->setEnv('DEBUG', 'true');
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, '1');
        $this->assertTrue(CookieSecurity::enabled());
    }

    public function testExplicitOffWinsEvenInProduction(): void
    {
        // Intentional HTTP staging box: pin it off despite debug being disabled.
        $this->setEnv('DEBUG', '0');
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, '0');
        $this->assertFalse(CookieSecurity::enabled());
    }

    public function testEmptyOverrideFallsBackToDebugDefault(): void
    {
        // An empty SESSION_COOKIE_SECURE is treated as "unset", not as false.
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, '');
        $this->setEnv('DEBUG', '0');
        $this->assertTrue(CookieSecurity::enabled());
    }

    public function testUnsetDebugIsTreatedAsProduction(): void
    {
        // DEBUG absent entirely -> production semantics (matches the `debug` key's
        // own env('DEBUG', false) default) -> Secure ON.
        $this->setEnv(CookieSecurity::ENV_OVERRIDE, null);
        $this->setEnv('DEBUG', null);
        $this->assertTrue(CookieSecurity::enabled());
    }
}
