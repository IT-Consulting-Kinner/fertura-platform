<?php
declare(strict_types=1);

namespace App\Auth;

use App\Service\Settings\SettingsManager;

/**
 * Password policy (ch. 1.4 / 27): reads the rules from the configuration store
 * (DB), with safe defaults from the catalog. Replaces the hard-coded defaults
 * from Step 2.
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
     * @return list<string> List of violations (empty = valid).
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
