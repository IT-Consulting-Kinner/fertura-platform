<?php
declare(strict_types=1);

namespace App\Service\System;

use App\Infrastructure\Db;
use App\Service\Module\ModuleInstallRunner;
use App\Service\Module\ModuleLifecycle;
use RuntimeException;

/**
 * The module-install critical action — Phase 6 reference handler.
 *
 * payload: {package_path, module_key}. execute() runs the DDL-heavy
 * install (the worst mutation path — non-transactional: filesystem + CREATE SCHEMA +
 * module migrations + core rows + grants). verify() asserts the module landed
 * (modules row + schema). rollback() is the existing best-effort action-own undo
 * ({@see ModuleLifecycle::purge()} → rollbackInstall), used when a post-install
 * VERIFY fails (install() only self-rolls-back on its own execute failure).
 */
class ModuleInstallHandler implements CriticalActionHandler
{
    /**
     * The key the install actually produced — set in execute(), used by verify/rollback.
     */
    private ?string $installedKey = null;

    public function __construct(
        private ModuleInstallRunner $runner = new ModuleInstallRunner(),
        private ModuleLifecycle $lifecycle = new ModuleLifecycle(),
    ) {
    }

    public function type(): string
    {
        return 'module_install';
    }

    public function execute(array $payload): void
    {
        $mod = $this->runner->installPackage((string)($payload['package_path'] ?? ''));
        // Capture the manifest's actual key so verify/rollback don't depend on the
        // enqueuer reading the package. The runner uses ONE handler instance per
        // action (fresh each worker tick), so this never leaks between actions.
        $this->installedKey = (string)$mod['module_key'];
    }

    public function verify(array $payload): array
    {
        $key = $this->key($payload);
        if ($key === '') {
            return ['ok' => false, 'reason' => 'module_key fehlt im Payload.'];
        }
        // Privileged read: install runs in the (superuser) worker; the modules table
        // + pg_namespace must be read without the app-role RLS view.
        $conn = Db::privileged();
        $mod = $conn->execute('SELECT status FROM modules WHERE module_key = :k', ['k' => $key])->fetch('assoc');
        if ($mod === false) {
            return ['ok' => false, 'reason' => 'Modul-Datensatz fehlt nach Install: ' . $key];
        }
        if (!in_array((string)$mod['status'], ['installed_inactive', 'active'], true)) {
            return ['ok' => false, 'reason' => 'Modul-Status unerwartet: ' . $mod['status']];
        }
        $schema = $conn->execute(
            'SELECT 1 FROM pg_namespace WHERE nspname = :s',
            ['s' => 'mod_' . $key],
        )->fetch('assoc');
        if ($schema === false) {
            return ['ok' => false, 'reason' => 'Modul-Schema fehlt: mod_' . $key];
        }

        return ['ok' => true, 'reason' => null];
    }

    public function rollback(array $payload): void
    {
        $key = $this->key($payload);
        if ($key === '') {
            return;
        }
        $this->lifecycle->purge($key);
        // purge() is best-effort — each cleanup step swallows its own error. Verify it
        // actually removed the module; a remnant means the action-own undo did NOT
        // fully succeed, so we throw and let the runner escalate to
        // needs_manual_restore instead of reporting a clean rollback.
        $conn = Db::privileged();
        $modLeft = $conn->execute('SELECT 1 FROM modules WHERE module_key = :k', ['k' => $key])->fetch('assoc');
        $schemaLeft = $conn->execute(
            'SELECT 1 FROM pg_namespace WHERE nspname = :s',
            ['s' => 'mod_' . $key],
        )->fetch('assoc');
        if ($modLeft !== false || $schemaLeft !== false) {
            throw new RuntimeException('Modul-Rollback unvollstaendig — Reste fuer ' . $key . ' verblieben.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function key(array $payload): string
    {
        return $this->installedKey ?? (string)($payload['module_key'] ?? '');
    }
}
