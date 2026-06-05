<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Service\Registry\SemVer;
use App\Service\Registry\VersionConstraint;
use InvalidArgumentException;
use Throwable;

/**
 * Modul-Manifest (manifest.json), Kap. 24.4–24.7.
 *
 * Pflichtfelder: id, name, version, type, edition, core_compatibility, publisher,
 * php_namespace. Extension-Module zusätzlich: extends_main_module,
 * main_module_compatibility. Typregel (24.7.3): Main-Module dürfen kein
 * contracts_used deklarieren.
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
     * Validiert das Manifest. Gibt eine Liste der Fehler zurück (leer = gültig).
     *
     * @return list<string>
     */
    public function validate(string $coreVersion): array
    {
        $errors = [];
        $required = ['id', 'name', 'version', 'type', 'edition', 'core_compatibility', 'php_namespace'];
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

        // Typregel 24.7.3: Main-Module dürfen kein contracts_used deklarieren.
        if ($this->type() === 'main' && $this->contractsUsed() !== []) {
            $errors[] = 'Main-Module dürfen kein contracts_used deklarieren (Kap. 24.7.3).';
        }

        // Extension-Pflichtfelder.
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
