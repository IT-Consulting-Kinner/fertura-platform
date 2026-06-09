<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Search\SearchService;
use Cake\Http\Response;

/**
 * Volltextsuche über die API (P10): `GET /api/v1/search?q=…` — sichtbarkeits-
 * gefiltert auf den Token-Inhaber (Scope `me:read`).
 */
class SearchController extends ApiController
{
    public function index(): Response
    {
        if ($denied = $this->requireScope('me:read')) {
            return $denied;
        }
        $q = (string)$this->request->getQuery('q', '');
        $limit = max(1, min(50, (int)$this->request->getQuery('limit', 20)));
        $results = (new SearchService())->search($q, $this->userId() ?: null, $limit);

        return $this->json(['query' => $q, 'results' => $results, 'count' => count($results)]);
    }
}
