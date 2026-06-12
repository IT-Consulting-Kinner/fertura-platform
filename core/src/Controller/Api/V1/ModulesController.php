<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;

/**
 * GET /api/v1/modules — installierte Module (Scope `modules:read`).
 */
class ModulesController extends ApiController
{
    public function index(): Response
    {
        if ($denied = $this->requireScope('modules:read')) {
            return $denied;
        }
        $rows = ConnectionManager::get('default')->execute(
            'SELECT module_key, name, version, type, status FROM modules ORDER BY module_key',
        )->fetchAll('assoc');

        return $this->json(['modules' => $rows, 'count' => count($rows)]);
    }
}
