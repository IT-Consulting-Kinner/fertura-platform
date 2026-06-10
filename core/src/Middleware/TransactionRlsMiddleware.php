<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Permission\PermissionService;
use App\Service\Permission\RlsContext;
use App\Service\Settings\SettingsManager;
use App\Service\Tenant\TenantResolver;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Hüllt jeden Request in eine DB-Transaktion und setzt den RLS-Zugriffskontext
 * via SET LOCAL (Entscheidung 175). Muss NACH der AuthenticationMiddleware
 * laufen (benötigt die Identität).
 */
class TransactionRlsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('default');
        $connection->begin();
        try {
            $identity = $request->getAttribute('identity');
            $userId = $identity !== null ? (string)$identity->getIdentifier() : null;
            $groupIds = $userId !== null ? (new PermissionService())->activeGroupIds($userId) : [];
            // Mandantenkontext aus dem angemeldeten Benutzer ableiten (Single-Org:
            // Default-Mandant). Pre-Auth (kein Benutzer): aus dem Request-Host
            // auflösen (mandantenspezifische Login-/SSO-Oberfläche), sonst null →
            // mandanten-bezogene Daten unsichtbar (fail-closed).
            $hostTenant = (new TenantResolver())->resolve($request->getUri()->getHost());
            if ($userId !== null) {
                $tenantId = (new TenantService())->tenantIdForUser($userId);
                // Cross-Tenant-Host-Policy: ein angemeldeter Benutzer auf der Domain
                // eines FREMDEN Mandanten wird abgewiesen (sofern aktiviert und der
                // Host überhaupt einem Mandanten zugeordnet ist). Single-Org/Default-
                // Host lösen auf null auf -> kein Konflikt.
                if ($hostTenant !== null && $hostTenant !== $tenantId && $this->enforceHostMatch()) {
                    throw new ForbiddenException('Zugriff auf einen fremden Mandanten-Host.');
                }
            } else {
                $tenantId = $hostTenant;
            }
            (new RlsContext())->applyLocal($connection, $userId, $groupIds, false, $tenantId);

            $response = $handler->handle($request);
            $connection->commit();

            return $response;
        } catch (Throwable $e) {
            $connection->rollback();

            throw $e;
        }
    }

    private function enforceHostMatch(): bool
    {
        try {
            return (bool)(new SettingsManager())->get('core', 'tenancy.enforce_host_match', true);
        } catch (Throwable) {
            return true;
        }
    }
}
