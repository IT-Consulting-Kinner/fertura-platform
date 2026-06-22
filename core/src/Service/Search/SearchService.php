<?php
declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Ai\EmbeddingService;
use App\Service\Module\ContributionRuntime;
use App\Service\Settings\SettingsManager;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Full-text search (program tier 1, P10) over a central `search_index`
 * (Postgres `tsvector`). Core/modules store documents; hits are sorted by
 * `ts_rank` and **visibility-filtered** (by owner): a caller sees only their own
 * documents and those without an owner (public).
 */
class SearchService
{
    public const COLLECTOR = 'core.collector.search';

    public function __construct(
        private ?ContributionRuntime $runtime = null,
        private ?EmbeddingService $embeddings = null,
    ) {
    }

    private function embeddings(): EmbeddingService
    {
        return $this->embeddings ??= new EmbeddingService();
    }

    /**
     * Full-text language configuration (must match the tsvector column). Strictly
     * validated against `[a-z_]` (safe interpolation into the regconfig position).
     */
    private function textConfig(): string
    {
        try {
            $cfg = (string)(new SettingsManager())->get('core', 'search.text_config', 'simple');
        } catch (Throwable) {
            $cfg = 'simple';
        }

        return preg_match('/^[a-z_]+$/', $cfg) === 1 ? $cfg : 'simple';
    }

    /** Current tenant from the RLS context (falls back to the default tenant). */
    private function tenantId(): string
    {
        try {
            $row = $this->conn()->execute(
                "SELECT coalesce(nullif(current_setting('app.current_tenant_id', true), ''), :d) AS t",
                ['d' => TenantService::DEFAULT_TENANT_ID],
            )->fetch('assoc');

            return (string)($row['t'] ?? TenantService::DEFAULT_TENANT_ID);
        } catch (Throwable) {
            return TenantService::DEFAULT_TENANT_ID;
        }
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Stores or updates a searchable document (idempotent over
     * source+entity_type+entity_id).
     */
    public function index(
        string $source,
        string $entityType,
        string $entityId,
        string $title,
        string $body = '',
        ?string $ownerId = null,
        ?string $url = null,
        ?bool $embed = null,
    ): void {
        $tenantId = $this->tenantId();
        $this->conn()->execute(
            'INSERT INTO search_index (tenant_id, source, entity_type, entity_id, title, body, owner_id, url) '
            . 'VALUES (:tid, :s, :et, :ei, :ti, :b, :o, :u) '
            . 'ON CONFLICT (tenant_id, source, entity_type, entity_id) DO UPDATE SET '
            . 'title = EXCLUDED.title, body = EXCLUDED.body, owner_id = EXCLUDED.owner_id, '
            . 'url = EXCLUDED.url, updated_at = now()',
            ['tid' => $tenantId, 's' => $source, 'et' => $entityType, 'ei' => $entityId, 'ti' => $title, 'b' => $body, 'o' => $ownerId, 'u' => $url],
        );

        // Optionally embed the same document for hybrid search (best-effort:
        // full-text stays authoritative, an embedding failure must never break
        // indexing). Controlled per call ($embed) or globally (ai.embed.auto_index).
        if ($this->shouldEmbed($embed)) {
            try {
                $content = trim($title . "\n" . $body);
                $this->embeddings()->index($source, $entityType, $entityId, $content, $ownerId, $tenantId);
            } catch (Throwable) {
            }
        }
    }

    public function remove(string $source, string $entityType, string $entityId): void
    {
        $this->conn()->execute(
            'DELETE FROM search_index WHERE tenant_id = :tid AND source = :s AND entity_type = :et AND entity_id = :ei',
            ['tid' => $this->tenantId(), 's' => $source, 'et' => $entityType, 'ei' => $entityId],
        );
        // Remove the embedding synchronously as well (best-effort), so both
        // indexes stay consistent.
        try {
            $this->embeddings()->remove($source, $entityType, $entityId);
        } catch (Throwable) {
        }
    }

    public function removeSource(string $source): void
    {
        $this->conn()->execute(
            'DELETE FROM search_index WHERE tenant_id = :tid AND source = :s',
            ['tid' => $this->tenantId(), 's' => $source],
        );
        try {
            $this->embeddings()->removeSource($source);
        } catch (Throwable) {
        }
    }

    /**
     * Decides whether to additionally embed during indexing: the explicit
     * parameter takes precedence, otherwise the `ai.embed.auto_index` setting; in
     * both cases only if an embedding provider is available at all.
     */
    private function shouldEmbed(?bool $explicit): bool
    {
        if ($explicit === false) {
            return false;
        }
        if ($explicit === null) {
            try {
                if (!(bool)(new SettingsManager())->get('core', 'ai.embed.auto_index', false)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return $this->embeddings()->available();
    }

    /**
     * Full-text search. `$userId === null` = system/admin (no visibility
     * restriction); otherwise only own + public documents.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,title:string,url:?string,rank:float}>
     */
    public function search(string $query, ?string $userId = null, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        // Tenant-scoped (closes the cross-tenant leak of public documents) +
        // owner visibility (own + public WITHIN the tenant).
        $scope = $userId === null ? '' : ' AND (owner_id IS NULL OR owner_id = :uid)';
        $params = ['q' => $q, 'l' => $limit, 'tid' => $this->tenantId()];
        if ($userId !== null) {
            $params['uid'] = $userId;
        }

        // Language-aware: `simple` (exact) OR the configured language (stemming).
        $cfg = $this->textConfig();
        $tsq = $cfg === 'simple'
            ? "websearch_to_tsquery('simple', :q)"
            : "(websearch_to_tsquery('simple', :q) || websearch_to_tsquery('$cfg', :q))";

        $rows = $this->conn()->execute(
            'SELECT source, entity_type, entity_id, title, url, '
            . "ts_rank(tsv, $tsq) AS rank "
            . 'FROM search_index '
            . "WHERE tenant_id = :tid AND tsv @@ $tsq" . $scope . ' '
            . 'ORDER BY rank DESC, updated_at DESC LIMIT :l',
            $params,
        )->fetchAll('assoc');

        return array_map(static fn(array $r): array => [
            'source' => (string)$r['source'],
            'entity_type' => (string)$r['entity_type'],
            'entity_id' => (string)$r['entity_id'],
            'title' => (string)$r['title'],
            'url' => $r['url'] !== null ? (string)$r['url'] : null,
            'rank' => (float)$r['rank'],
        ], $rows);
    }

    /**
     * **Hybrid search**: fuses full-text (`tsvector`/`ts_rank`) and semantic vector
     * similarity (pgvector embeddings) via **Reciprocal Rank Fusion** (RRF).
     * Combines lexical precision (exact terms) with semantic recall
     * (meaning/synonyms). If no embedding provider is configured (or the embedding
     * fails), the method degrades cleanly to pure full-text search.
     *
     * Visibility (by owner) is filtered identically in both halves.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,title:string,url:?string,score:float}>
     */
    public function hybrid(string $query, ?string $userId = null, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        // Wider candidate sets per side -> better fusion, then trim to $limit.
        $candidates = max($limit, 50);
        $fts = $this->search($q, $userId, $candidates);

        $vec = [];
        try {
            $emb = $this->embeddings();
            if ($emb->available()) {
                $vec = $emb->semantic($q, $userId, $candidates);
            }
        } catch (Throwable) {
            $vec = []; // AI/embeddings unavailable -> pure full-text search
        }

        return $this->fuse($fts, $vec, $limit);
    }

    /**
     * Reciprocal Rank Fusion of two ranked hit lists keyed by
     * (source, entity_type, entity_id). Score = Σ 1/(k + rank); k=60 (default).
     *
     * @param list<array<string,mixed>> $fts Full-text hits (with title/url)
     * @param list<array<string,mixed>> $vec Vector hits (with content)
     * @return list<array{source:string,entity_type:string,entity_id:string,title:string,url:?string,score:float}>
     */
    private function fuse(array $fts, array $vec, int $limit): array
    {
        $k = 60;
        $scores = [];
        $meta = [];
        $accumulate = static function (array $list) use (&$scores, &$meta, $k): void {
            foreach (array_values($list) as $i => $r) {
                $key = $r['source'] . '|' . $r['entity_type'] . '|' . $r['entity_id'];
                $scores[$key] = ($scores[$key] ?? 0.0) + 1.0 / ($k + $i + 1);
                if (!isset($meta[$key])) {
                    // First source wins the metadata — FTS (with title/url) is fed
                    // in first and therefore preferred; pure vector hits fall back
                    // to a content excerpt as the title.
                    $meta[$key] = [
                        'source' => (string)$r['source'],
                        'entity_type' => (string)$r['entity_type'],
                        'entity_id' => (string)$r['entity_id'],
                        'title' => (string)($r['title'] ?? mb_substr((string)($r['content'] ?? ''), 0, 120)),
                        // isset() already excludes null — no extra !== null check needed.
                        'url' => isset($r['url']) ? (string)$r['url'] : null,
                    ];
                }
            }
        };
        $accumulate($fts);
        $accumulate($vec);

        arsort($scores);
        $out = [];
        foreach (array_slice(array_keys($scores), 0, $limit) as $key) {
            $out[] = $meta[$key] + ['score' => round($scores[$key], 6)];
        }

        return $out;
    }

    /**
     * Generates embeddings for already-indexed search documents that have none yet
     * (e.g. those indexed before `ai.embed.auto_index` was enabled). Makes hybrid
     * search usable for existing data without re-running the module indexers.
     * Returns the number of newly embedded documents (0 if no embedding provider
     * is available).
     */
    public function backfillEmbeddings(int $limit = 500): int
    {
        if (!$this->embeddings()->available()) {
            return 0;
        }
        $rows = $this->conn()->execute(
            'SELECT s.tenant_id, s.source, s.entity_type, s.entity_id, s.title, s.body, s.owner_id '
            . 'FROM search_index s '
            . 'LEFT JOIN embeddings e '
            . '  ON e.tenant_id = s.tenant_id AND e.source = s.source '
            . '  AND e.entity_type = s.entity_type AND e.entity_id = s.entity_id '
            . 'WHERE e.entity_id IS NULL '
            . 'ORDER BY s.updated_at DESC LIMIT :l',
            ['l' => max(1, $limit)],
        )->fetchAll('assoc');

        $done = 0;
        foreach ($rows as $r) {
            try {
                $content = trim((string)$r['title'] . "\n" . (string)$r['body']);
                $this->embeddings()->index(
                    (string)$r['source'],
                    (string)$r['entity_type'],
                    (string)$r['entity_id'],
                    $content,
                    $r['owner_id'] !== null ? (string)$r['owner_id'] : null,
                    (string)$r['tenant_id'], // carry over the tenant of the source row
                );
                $done++;
            } catch (Throwable) {
            }
        }

        return $done;
    }

    /**
     * Triggers a full rebuild via the module indexers (`core.collector.search`).
     * Returns the number of indexers invoked.
     */
    public function reindexAll(): int
    {
        $runtime = $this->runtime ??= new ContributionRuntime();
        $count = 0;
        try {
            // A full reindex is an operator/platform rebuild (runs RLS-bypassed):
            // invoke every module's indexer regardless of per-tenant enablement
            // (un-gated, Phase 2). Each indexer scopes its own per-tenant content.
            foreach ($runtime->collectors(self::COLLECTOR, false) as $contrib) {
                try {
                    $runtime->call($contrib, 'reindex', [], ['bypass' => true]);
                    $count++;
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }

        return $count;
    }
}
