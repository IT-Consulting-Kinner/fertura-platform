<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use App\Service\System\MaintenanceMode;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Maintenance mode (ch. 28.11): when `core.maintenance_mode` is active the
 * platform returns 503 (no regular usage), while lifecycle/update operations
 * continue to run via the CLI.
 */
class MaintenanceMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // File flag (restore cutover, survives a DB restore) OR DB setting.
        $active = MaintenanceMode::isFileActive();
        if (!$active) {
            try {
                $active = (bool)(new SettingsManager())->get('core', 'maintenance_mode', false);
            } catch (Throwable) {
                $active = false;
            }
        }

        if ($active) {
            return (new Response())
                ->withStatus(503)
                ->withType('text/plain')
                ->withStringBody("Wartungsmodus aktiv. Bitte später erneut versuchen.\n");
        }

        return $handler->handle($request);
    }
}
