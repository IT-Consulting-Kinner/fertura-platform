<?php
declare(strict_types=1);

namespace App\Auth;

use App\Service\Settings\SettingsManager;

/**
 * Passwort-Policy (Kap. 1.4 / 27): liest die Regeln aus dem Konfigurationsspeicher
 * (DB), mit sicheren Vorgabewerten aus dem Katalog. Ersetzt die festen Code-
 * Defaults aus Step 2.
 */
class PasswordPolicy
{
    private SettingsManager $settings;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings ?? new SettingsManager();
    }

    public function minLength(): int
    {
        return (int)$this->settings->get('core', 'password.min_length', 12);
    }

    /**
     * @return list<string> Liste der Verstöße (leer = gültig).
     */
    public function validate(string $plain): array
    {
        $errors = [];
        $min = $this->minLength();
        if (mb_strlen($plain) < $min) {
            $errors[] = "Passwort muss mindestens $min Zeichen lang sein.";
        }

        return $errors;
    }
}
