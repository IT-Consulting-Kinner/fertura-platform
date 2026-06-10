<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Search\SearchService;

/**
 * Globale Admin-Suche: durchsucht den zentralen Index (Hybrid = Volltext +
 * semantisch, mit Rückfall auf Volltext) für jeden Admin. Sichtbarkeit wird auf
 * den Aufrufer gefiltert (eigene + öffentliche Dokumente). Gebaut mit dem UI-Kit.
 */
class SearchController extends AdminController
{
    protected ?string $requiredArea = null; // jeder Admin

    public function index(): void
    {
        $qRaw = $this->request->getQuery('q');
        $q = is_string($qRaw) ? $qRaw : '';
        $results = [];
        if (trim($q) !== '') {
            $identity = $this->identity();
            $uid = $identity !== null ? (string)$identity->getIdentifier() : '';
            $results = (new SearchService())->hybrid($q, $uid !== '' ? $uid : null, 30);
        }
        $this->set(compact('q', 'results'));
    }
}
