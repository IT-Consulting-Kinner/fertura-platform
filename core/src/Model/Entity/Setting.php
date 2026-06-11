<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Configuration value (core.settings).
 *
 * @property string $id
 * @property string $namespace
 * @property string $config_key
 * @property mixed $value
 * @property string|null $value_encrypted
 * @property bool $is_secret
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $updated_at
 */
class Setting extends Entity
{
    /**
     * Managed through the SettingsManager (no mass assignment from user input).
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];
}
