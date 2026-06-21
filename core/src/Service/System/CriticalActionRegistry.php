<?php
declare(strict_types=1);

namespace App\Service\System;

/**
 * Core-internal map of critical-action type → {@see CriticalActionHandler}
 * (Phase 6). Kept inside Core (not a module-contributed registry) so the
 * Core-never-edits-modules boundary holds while still allowing per-type logic.
 * Phase 6 follow-up adds tenant_provision / secret_rotate / trust_rotate handlers.
 */
class CriticalActionRegistry
{
    /** @var array<string, CriticalActionHandler> */
    private array $handlers = [];

    /** @param list<CriticalActionHandler>|null $handlers override for tests */
    public function __construct(?array $handlers = null)
    {
        foreach ($handlers ?? self::defaults() as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    /** @return list<CriticalActionHandler> */
    private static function defaults(): array
    {
        return [
            new ModuleInstallHandler(),
            new TenantProvisionHandler(),
        ];
    }

    public function get(string $type): ?CriticalActionHandler
    {
        return $this->handlers[$type] ?? null;
    }
}
