<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Search\SearchService;
use Cake\Http\Response;

/**
 * Full-text search via the API (P10): `GET /api/v1/search?q=…` — visibility-
 * filtered to the token holder (scope `me:read`).
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
        $uid = $this->userId() ?: null;
        // mode=fts forces pure full-text search; otherwise hybrid (full-text + semantic),
        // which internally falls back to full-text when no embedding provider is active.
        $mode = (string)$this->request->getQuery('mode', 'auto');
        $svc = new SearchService();
        $results = $mode === 'fts'
            ? $svc->search($q, $uid, $limit)
            : $svc->hybrid($q, $uid, $limit);

        return $this->json([
            'query' => $q,
            'mode' => $mode === 'fts' ? 'fts' : 'hybrid',
            'results' => $results,
            'count' => count($results),
        ]);
    }
}
