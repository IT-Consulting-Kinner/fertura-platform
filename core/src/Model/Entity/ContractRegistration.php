<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Registration against a contract (core.contract_registrations).
 *
 * @property string $id
 * @property string $contract_id
 * @property string $module_key
 * @property string|null $module_version
 * @property string $registration_type
 * @property string|null $implementation_class
 * @property string|null $required_version
 * @property int $priority
 * @property bool $active
 */
class ContractRegistration extends Entity
{
    public const TYPE_PROVIDER = 'provider';
    public const TYPE_COLLECTOR = 'collector_contribution';
    public const TYPE_LISTENER = 'listener';
    public const TYPE_CONSUMER = 'service_consumer';

    protected array $_accessible = ['*' => false];
}
