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
 * Wraps every request in a DB transaction and sets the RLS access context
 * via SET LOCAL (Decision 175). Must run AFTER the AuthenticationMiddleware
 * (requires the identity).
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
            // Derive the tenant context from the authenticated user (single-org:
            // default tenant). Pre-auth (no user): resolve from the request host
            // (tenant-specific login/SSO frontend), otherwise null →
            // tenant-scoped data is invisible (fail-closed).
            $hostTenant = (new TenantResolver())->resolve($request->getUri()->getHost());
            if ($userId !== null) {
                $tenantId = (new TenantService())->tenantIdForUser($userId);
                // Cross-tenant host policy: an authenticated user on the domain of
                // a FOREIGN tenant is rejected (provided this is enabled and the
                // host is mapped to a tenant at all). Single-org/default hosts
                // resolve to null -> no conflict.
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
