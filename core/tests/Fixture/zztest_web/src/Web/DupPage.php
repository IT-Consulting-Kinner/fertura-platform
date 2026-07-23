<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

use App\Service\Module\ModuleWebInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Fixture web page that DELIBERATELY inserts a uniquely-constrained row WITHOUT any
 * pre-check / catch / ON CONFLICT — so a duplicate `name` raises a raw PostgreSQL
 * 23505 that propagates out of the module handler. It proves the Core module
 * dispatcher ({@see \App\Controller\ModuleWebController}) routes that violation to
 * {@see \App\Middleware\UniqueViolationMiddleware} (a warning + redirect) instead of
 * masking it as a generic 500 — i.e. that the unique-violation net covers module
 * writes, not just Core ones.
 */
final class DupPage implements ModuleWebInterface
{
    /**
     * @param array{method:string,path:string,params:array<string,string>,
     *     query:array<string,mixed>,body:mixed,user_id:?string,client_ip:?string} $request
     * @return array{vars?:array<string,mixed>,status?:int,template?:string,redirect?:string}
     */
    public function handle(array $request): array
    {
        $body = (array)$request['body'];
        if ($request['method'] === 'POST' && ($body['action'] ?? '') === 'create') {
            /** @var \Cake\Database\Connection $conn */
            $conn = ConnectionManager::get('default');
            // No pre-check, no catch, no ON CONFLICT: a duplicate name -> raw 23505.
            $conn->execute(
                'INSERT INTO mod_zztest_web.things (name) VALUES (:n)',
                ['n' => (string)($body['name'] ?? '')],
            );

            return ['redirect' => '/m/zztest_web/admin/dup?created=1'];
        }

        return ['vars' => ['heading' => 'Dup-Netz-Testseite', 'userId' => $request['user_id']]];
    }
}
