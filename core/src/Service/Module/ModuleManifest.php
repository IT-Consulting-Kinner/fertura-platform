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

        if (!in_array($this->type(), ['main', 'extension'], true)) {
            $errors[] = "Ungültiger Typ: {$this->type()} (main|extension).";
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

        return $errors;
    }
}
