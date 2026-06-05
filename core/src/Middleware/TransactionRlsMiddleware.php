<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Permission\PermissionService;
use App\Service\Permission\RlsContext;
use Cake\Datasource\ConnectionManager;
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
            (new RlsContext())->applyLocal($connection, $userId, $groupIds, false);

            $response = $handler->handle($request);
            $connection->commit();

            return $response;
        } catch (Throwable $e) {
            $connection->rollback();

            throw $e;
        }
    }
}
