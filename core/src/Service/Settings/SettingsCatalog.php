<?php
declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Katalog der bekannten Core-Settings: sichere Vorgabewerte (greifen auch ohne
 * DB-Eintrag, Kap. 27.16.3 / Entscheidung 162), Typ- und Wertebereichs-
 * validierung sowie das Secret-Flag (verschlüsselte Ablage, Entscheidung 159).
 *
 * Definition je Schlüssel: type (int|bool|string|json), default, optional
 * min/max (für int) und secret (bool).
 */
class SettingsCatalog
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private const DEFINITIONS = [
        'core' => [
            // Passwort-Policy (in Step 2 als Code-Default, jetzt DB-konfigurierbar).
            'password.min_length' => ['type' => 'int', 'default' => 12, 'min' => 6, 'max' => 128],
            // Anmeldeschutz (Entscheidung 162).
            'login_throttle.max_attempts' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 1000],
            'login_throttle.window_minutes' => ['type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440],
            // Session-Timeout (Wiring an die Session folgt mit der GUI, Step 10).
            'session.timeout_minutes' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 43200],
            // Beispiel für ein verschlüsseltes Geheimnis.
            'smtp.password' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Marketplace / Signatur / Wartung (Step 8).
            'require_module_signature' => ['type' => 'bool', 'default' => true],
            'crl_max_age_days' => ['type' => 'int', 'default' => 7, 'min' => 0, 'max' => 365],
            'marketplace.base_url' => ['type' => 'string', 'default' => null],
            'maintenance_mode' => ['type' => 'bool', 'default' => false],
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $namespace, string $key): ?array
    {
        return self::DEFINITIONS[$namespace][$key] ?? null;
    }

    public function default(string $namespace, string $key): mixed
    {
        return self::DEFINITIONS[$namespace][$key]['default'] ?? null;
    }

    public function isSecret(string $namespace, string $key): bool
    {
        return (bool)(self::DEFINITIONS[$namespace][$key]['secret'] ?? false);
    }

    public function isKnown(string $namespace, string $key): bool
    {
        return isset(self::DEFINITIONS[$namespace][$key]);
    }

    /**
     * Validiert einen Wert gegen die Katalogdefinition.
     *
     * @return list<string> Liste der Fehlermeldungen (leer = gültig).
     */
    public function validate(string $namespace, string $key, mixed $value): array
    {
        $def = $this->definition($namespace, $key);
        if ($def === null) {
            return ["Unbekanntes Setting: $namespace.$key"];
        }
        if ($value === null) {
            return [];
        }

        $errors = [];
        switch ($def['type']) {
            case 'int':
                if (!is_int($value)) {
                    $errors[] = "$key muss eine Ganzzahl sein.";
                    break;
                }
                if (isset($def['min']) && $value < $def['min']) {
                    $errors[] = "$key muss >= {$def['min']} sein.";
                }
                if (isset($def['max']) && $value > $def['max']) {
                    $errors[] = "$key muss <= {$def['max']} sein.";
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = "$key muss ein Boolescher Wert sein.";
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $errors[] = "$key muss eine Zeichenkette sein.";
                }
                break;
            case 'json':
                if (!is_array($value)) {
                    $errors[] = "$key muss ein strukturierter Wert (Array/Objekt) sein.";
                }
                break;
        }

        return $errors;
    }
}
