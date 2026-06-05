<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Registrierter Contract (core.contracts).
 *
 * @property string $id
 * @property string $owner_module_key
 * @property string $name
 * @property string $contract_type
 * @property string $version
 * @property array|null $input_spec
 * @property array|null $output_spec
 * @property array|null $default_behavior
 * @property bool $multi_use
 * @property string|null $description
 * @property bool $active
 */
class Contract extends Entity
{
    public const TYPE_RESOLVER = 'resolver';
    public const TYPE_COLLECTOR = 'collector';
    public const TYPE_EVENT = 'event';
    public const TYPE_SERVICE = 'service';

    protected array $_accessible = ['*' => false];
}
