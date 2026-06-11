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

    /**
     * Stößt den Volltext-Reindex an und zieht fehlende Embeddings nach (GUI-
     * Pendant zu `bin/cake search_reindex`). Wartungsaktion → nur core_config.
     */
    public function reindex(): ?\Cake\Http\Response
    {
        $this->request->allowMethod('post');
        if (!in_array('core_config', $this->userAreaKeys, true)) {
            $this->Flash->error(__('flash.search.reindex_denied'));

            return $this->redirect(['action' => 'index']);
        }
        try {
            $svc = new SearchService();
            $indexers = $svc->reindexAll();
            $embeddings = $svc->backfillEmbeddings(500);
            $this->Flash->success(__('flash.search.reindexed', $indexers, $embeddings));
        } catch (\Throwable $e) {
            $this->Flash->error(__('flash.search.reindex_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
