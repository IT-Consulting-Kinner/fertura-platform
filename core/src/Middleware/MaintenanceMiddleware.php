<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Settings\SettingsManager;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Wartungsmodus (Kap. 28.11): bei aktivem `core.maintenance_mode` liefert die
 * Plattform 503 (keine reguläre Nutzung), während Lifecycle-/Update-Operationen
 * über die CLI weiterlaufen.
 */
class MaintenanceMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $active = false;
        try {
            $active = (bool)(new SettingsManager())->get('core', 'maintenance_mode', false);
        } catch (Throwable) {
            $active = false;
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
