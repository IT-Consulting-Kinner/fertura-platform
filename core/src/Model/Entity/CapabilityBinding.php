<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Persistierte Capability-Bindung (core.capability_bindings).
 *
 * @property string $id
 * @property string $module_key
 * @property string $contract_id
 * @property string|null $required_version
 * @property string $status
 * @property \Cake\I18n\DateTime|null $revoked_at
 */
class CapabilityBinding extends Entity
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected array $_accessible = ['*' => false];
}
