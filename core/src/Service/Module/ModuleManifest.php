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
 * Pflichtfelder (Kap. 24.4.1): id, name, version, type, edition, description,
 * core_compatibility, publisher, php_namespace. Extension-Module zusätzlich:
 * extends_main_module, main_module_compatibility. Typregel (24.7.3): Main-Module
 * dürfen kein contracts_used deklarieren.
 *
 * Hinweis zum Spec-Feld `entrypoint` (24.4.1, „Einstiegsklasse"): in dieser
 * Implementierung über `php_namespace` realisiert — der Namespace-Wurzelpfad,
 * aus dem der `ModuleAutoloader` den Modulcode lädt (E46). `signature` ist kein
 * Manifestfeld, sondern die separate Paketsignatur (`signature.json`,
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
     * Vom anbietenden Modul bereitgestellte Service-Implementierungen für eigene
     * Service-Contracts (öffentliche Modul-Interfaces, Kap. 29). Je Eintrag:
     * contract, version (Constraint), class (implementiert ServiceInterface).
     *
     * @return list<array<string, mixed>>
     */
    public function servicesRegistered(): array
    {
        return array_values($this->data['services_registered'] ?? []);
    }

    /** @return list<array<string, mixed>> Deklarierte BREAD-Ressourcen. */
    public function permissions(): array
    {
        return array_values($this->data['permissions'] ?? []);
    }

    /**
     * Sprachdateien des Pakets (i18n-4). `domain` = Übersetzungs-Domain (Default
     * = Modulschlüssel); `supported` = mitgelieferte Locales (mind. en_US).
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

    /** Sicherheitsupdate-Kennzeichnung (Kap. 28.10): `security: true` im Manifest. */
    public function isSecurityUpdate(): bool
    {
        return !empty($this->data['security']);
    }

    /** Dringlichkeit (optional): low|medium|high|critical. */
    public function severity(): ?string
    {
        return isset($this->data['severity']) ? (string)$this->data['severity'] : null;
    }

    /**
     * Validiert das Manifest. Gibt eine Liste der Fehler zurück (leer = gültig).
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
