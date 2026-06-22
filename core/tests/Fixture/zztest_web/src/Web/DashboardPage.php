<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

use App\Service\Module\ModuleWebInterface;

/**
 * Fixture web page handler: an authenticated module dashboard. Returns view
 * variables only — the Core dispatcher renders the template under its layout.
 */
final class DashboardPage implements ModuleWebInterface
{
    public function handle(array $request): array
    {
        return [
            'vars' => [
                'greeting' => 'Hallo aus dem Modul',
                'userId' => $request['user_id'],
                'echoedPath' => $request['path'],
                'echoedId' => $request['params']['id'] ?? null,
                // Increment 5 Phase 3: the per-tenant module config the Core injected.
                'configSuffix' => $request['module_config']['greeting_suffix'] ?? '',
            ],
        ];
    }
}
