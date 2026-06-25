<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Service\Registry\SemVer;
use App\Service\Registry\VersionConstraint;
use InvalidArgumentException;
use Throwable;

/**
 * Module manifest (manifest.json), ch. 24.4–24.7.
 *
 * Required fields (ch. 24.4.1): id, name, version, type, edition, description,
 * core_compatibility, publisher, php_namespace. Extension modules additionally:
 * extends_main_module, main_module_compatibility. Type rule (24.7.3): main
 * modules may not declare contracts_used.
 *
 * Note on the spec field `entrypoint` (24.4.1, "entry class"): in this
 * implementation it is realized via `php_namespace` — the namespace root path
 * from which the `ModuleAutoloader` loads the module code (E46). `signature` is
 * not a manifest field but the separate package signature (`signature.json`,
 * `PackageVerifier`).
 */
class ModuleManifest
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data)
    {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Manifest ist kein gültiges JSON-Objekt.');
        }

        return new self($data);
    }

    public function key(): string
    {
        return (string)($this->data['id'] ?? '');
    }

    public function name(): string
    {
        return (string)($this->data['name'] ?? '');
    }

    public function version(): string
    {
        return (string)($this->data['version'] ?? '');
    }

    public function type(): string
    {
        return (string)($this->data['type'] ?? '');
    }

    public function edition(): string
    {
        return (string)($this->data['edition'] ?? 'free');
    }

    public function publisher(): ?string
    {
        return isset($this->data['publisher']) ? (string)$this->data['publisher'] : null;
    }

    public function phpNamespace(): ?string
    {
        return isset($this->data['php_namespace']) ? (string)$this->data['php_namespace'] : null;
    }

    public function coreCompatibility(): string
    {
        return (string)($this->data['core_compatibility'] ?? '');
    }

    public function requiresLicense(): bool
    {
        return (bool)($this->data['requires_license'] ?? false);
    }

    /** @return list<array<string, mixed>> */
    public function dependencies(): array
    {
        return array_values($this->data['dependencies'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function contractsProvided(): array
    {
        return array_values($this->data['contracts_provided'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function contractsUsed(): array
    {
        return array_values($this->data['contracts_used'] ?? []);
    }

    /**
     * Hard integration relations of an integration-extension module (connector,
     * ch. 23.5.2/24.4.3): the main modules it bridges. Each entry: `module` (key)
     * and optionally `compatibility`/`version` (a constraint on that module).
     *
     * @return list<array<string, mixed>>
     */
    public function integrationRelations(): array
    {
        return array_values($this->data['integration_relations'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function resolversRegistered(): array
    {
        return array_values($this->data['resolvers_registered'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function collectorsRegistered(): array
    {
        return array_values($this->data['collectors_registered'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function eventsRegistered(): array
    {
        return array_values($this->data['events_registered'] ?? []);
    }

    /**
     * Service implementations provided by the offering module for its own
     * service contracts (public module interfaces, ch. 29). Each entry:
     * contract, version (constraint), class (implements ServiceInterface).
     *
     * @return list<array<string, mixed>>
     */
    public function servicesRegistered(): array
    {
        return array_values($this->data['services_registered'] ?? []);
    }

    /** @return list<array<string, mixed>> Declared BREAD resources. */
    public function permissions(): array
    {
        return array_values($this->data['permissions'] ?? []);
    }

    /**
     * Declared DB tables of the module's schema (Inc 9). Only EXCEPTIONS need listing:
     * an UNdeclared table is treated as tenant-scoped and must conform to the per-tenant
     * shape (tenant_id + RLS + policy + tenant-scoped uniques) the install gate enforces.
     * A table that is legitimately module-global (reference/lookup data with no tenant
     * dimension, e.g. `system_settings`) is opted OUT by declaring it here with
     * `scope: global` and a `reason`. Each entry: `table` (name), `scope`
     * (tenant_scoped|global), optional `reason`.
     *
     * @return list<array<string, mixed>>
     */
    public function tables(): array
    {
        return array_values($this->data['tables'] ?? []);
    }

    /**
     * The names of tables the module declares as module-GLOBAL (the install gate's
     * auditable exception list — these are NOT required to be tenant-scoped).
     *
     * @return list<string>
     */
    public function globalTables(): array
    {
        $out = [];
        foreach ($this->tables() as $t) {
            if (($t['scope'] ?? '') === 'global' && !empty($t['table'])) {
                $out[] = (string)$t['table'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Per-tenant configurable settings the module exposes (Increment 5 Phase 3).
     * Each entry: `key` (snake_case), `label` (i18n key), `type`
     * (bool|int|string|text|select); optional `default`, `help` (i18n), `required`,
     * `options` (for select: value => i18n label), `min`/`max` (for int). The Core
     * renders these as a per-tenant form ({@see \App\Controller\Admin\TenantModulesController})
     * and stores the values in `core.tenant_modules.config`.
     *
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array
    {
        return array_values($this->data['config_schema'] ?? []);
    }

    /**
     * Server-rendered web pages (ch. 23.16.3, web-mount). Each entry: path,
     * class ({@see ModuleWebInterface}), template; optional area/auth/title/
     * nav/nav_group.
     *
     * @return list<array<string, mixed>>
     */
    public function webRoutes(): array
    {
        return array_values($this->data['web_routes'] ?? []);
    }

    /**
     * Distinct admin areas declared by the module's web pages (a `web_routes`
     * entry with an `area`), mapped to their navigation group label (`nav_group`,
     * defaulting to the area key). These are registered in `core.admin_areas` on
     * activation so they become grantable.
     *
     * @return array<string, string> area key => label
     */
    public function adminAreas(): array
    {
        $areas = [];
        foreach ($this->webRoutes() as $r) {
            $area = isset($r['area']) && is_string($r['area']) ? $r['area'] : '';
            if ($area === '') {
                continue;
            }
            $navGroup = isset($r['nav_group']) && is_string($r['nav_group']) ? $r['nav_group'] : '';
            $areas[$area] = $navGroup !== '' ? $navGroup : $area;
        }

        return $areas;
    }

    /**
     * Language files of the package (i18n-4). `domain` = translation domain
     * (defaults to the module key); `supported` = bundled locales (at least en_US).
     *
     * @return array{domain: string, supported: list<string>}
     */
    public function locales(): array
    {
        $l = $this->data['locales'] ?? [];

        return [
            'domain' => (string)($l['domain'] ?? $this->key()),
            'supported' => array_values($l['supported'] ?? []),
        ];
    }

    /** Security-update flag (ch. 28.10): `security: true` in the manifest. */
    public function isSecurityUpdate(): bool
    {
        return !empty($this->data['security']);
    }

    /** Urgency (optional): low|medium|high|critical. */
    public function severity(): ?string
    {
        return isset($this->data['severity']) ? (string)$this->data['severity'] : null;
    }

    /**
     * Validates the manifest. Returns a list of errors (empty = valid).
     *
     * @return list<string>
     */
    public function validate(string $coreVersion): array
    {
        $errors = [];
        $required = ['id', 'name', 'version', 'type', 'edition', 'description', 'core_compatibility', 'publisher', 'php_namespace'];
        foreach ($required as $field) {
            if (empty($this->data[$field])) {
                $errors[] = "Pflichtfeld fehlt: $field";
            }
        }

        if (!in_array($this->type(), ['main', 'extension', 'integration'], true)) {
            $errors[] = "Ungültiger Typ: {$this->type()} (main|extension|integration).";
        }
        if (!in_array($this->edition(), ['free', 'commercial'], true)) {
            $errors[] = "Ungültige Edition: {$this->edition()} (free|commercial).";
        }

        try {
            SemVer::parse($this->version());
        } catch (Throwable $e) {
            $errors[] = 'version: ' . $e->getMessage();
        }

        if ($this->coreCompatibility() !== '') {
            try {
                $constraint = VersionConstraint::parse($this->coreCompatibility());
                if (!$constraint->isSatisfiedBy(SemVer::parse($coreVersion))) {
                    $errors[] = "core_compatibility {$this->coreCompatibility()} nicht erfüllt (Core $coreVersion).";
                }
            } catch (Throwable $e) {
                $errors[] = 'core_compatibility: ' . $e->getMessage();
            }
        }

        // Type rule 24.7.3: main modules may not declare contracts_used.
        if ($this->type() === 'main' && $this->contractsUsed() !== []) {
            $errors[] = 'Main-Module dürfen kein contracts_used deklarieren (Kap. 24.7.3).';
        }

        // Required fields for extensions.
        if ($this->type() === 'extension') {
            if (empty($this->data['extends_main_module'])) {
                $errors[] = 'Extension-Modul: extends_main_module fehlt.';
            }
            if (empty($this->data['main_module_compatibility'])) {
                $errors[] = 'Extension-Modul: main_module_compatibility fehlt.';
            }
        }

        // Integration-extension module (connector, ch. 23.5.2): bridges N main
        // modules via integration_relations and is a LEAF NODE (ch. 23.5.5) — it
        // does NOT anchor to a single main (no extends_main_module) and must NOT
        // provide any contracts (contracts_provided / resolvers / services).
        if ($this->type() === 'integration') {
            if ($this->integrationRelations() === []) {
                $errors[] = 'Integrations-Extension-Modul: integration_relations fehlt (mind. ein Main-Modul).';
            }
            if (
                $this->contractsProvided() !== []
                || $this->resolversRegistered() !== []
                || $this->servicesRegistered() !== []
            ) {
                $errors[] = 'Integrations-Extension-Modul (Blattknoten) darf keine Contracts bereitstellen '
                    . '(contracts_provided/resolvers_registered/services_registered, Kap. 23.5.5).';
            }
        }

        // Enhancing-not-gating (ch. 26.19, Decision 184): a provided resolver/service
        // contract must declare its absence semantics (error_behavior), so a missing
        // provider degrades to a defined neutral state (default behavior) instead of
        // breaking a mandatory flow. Enforced at activation, not only in the linter.
        foreach ($this->contractsProvided() as $i => $c) {
            $type = isset($c['type']) ? (string)$c['type'] : '';
            if (in_array($type, ['resolver', 'service'], true) && empty($c['error_behavior'])) {
                $name = !empty($c['name']) ? (string)$c['name'] : "#$i";
                $errors[] = "Contract $name: error_behavior fehlt "
                    . '(Resolver-/Service-Contract muss die Abwesenheits-/Default-Semantik deklarieren, Kap. 26.19).';
            }
        }

        return $errors;
    }
}
