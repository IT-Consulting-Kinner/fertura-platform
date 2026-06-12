<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

use App\Service\Module\ModuleWebInterface;

/**
 * Fixture web page handler: a module ADMIN page (declares an `area`), rendered
 * by the Core inside the admin shell with the scoped sidebar.
 */
final class AdminPage implements ModuleWebInterface
{
    public function handle(array $request): array
    {
        return [
            'vars' => [
                'heading' => 'Modul-Admin-Seite',
                'userId' => $request['user_id'],
            ],
        ];
    }
}
