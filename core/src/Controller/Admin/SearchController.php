<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Search\SearchService;
use Cake\Http\Response;
use Throwable;

/**
 * Global admin search: queries the central index (hybrid = full-text +
 * semantic, falling back to full-text) for any admin. Visibility is filtered
 * to the caller (own + public documents). Built with the UI kit.
 */
class SearchController extends AdminController
{
    protected ?string $requiredArea = null; // any admin

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
     * Triggers the full-text reindex and backfills missing embeddings (GUI
     * counterpart to `bin/cake search_reindex`). Maintenance action → core_config only.
     */
    public function reindex(): ?Response
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
        } catch (Throwable $e) {
            $this->Flash->error(__('flash.search.reindex_failed', $e->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }
}
