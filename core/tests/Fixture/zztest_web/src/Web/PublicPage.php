<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

use App\Service\Module\ModuleWebInterface;

/**
 * Fixture web page handler: a public (guest) module page — no login required.
 */
final class PublicPage implements ModuleWebInterface
{
    public function handle(array $request): array
    {
        return [
            'vars' => [
                'greeting' => 'Oeffentliche Modulseite',
                'userId' => $request['user_id'],
            ],
        ];
    }
}
