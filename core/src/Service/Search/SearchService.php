<?php
declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Ai\EmbeddingService;
use App\Service\Module\ContributionRuntime;
use App\Service\Settings\SettingsManager;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Volltext-Suche (Programm Tier-1, P10) über ein zentrales `search_index`
 * (Postgres `tsvector`). Core/Module legen Dokumente ab; Treffer werden nach
 * `ts_rank` sortiert und **sichtbarkeits-gefiltert** (Eigentümer): ein Aufrufer
 * sieht nur eigene Dokumente und solche ohne Eigentümer (öffentlich).
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

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Legt ein durchsuchbares Dokument ab bzw. aktualisiert es (idempotent über
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
        $this->conn()->execute(
            'INSERT INTO search_index (source, entity_type, entity_id, title, body, owner_id, url) '
            . 'VALUES (:s, :et, :ei, :ti, :b, :o, :u) '
            . 'ON CONFLICT (source, entity_type, entity_id) DO UPDATE SET '
            . 'title = EXCLUDED.title, body = EXCLUDED.body, owner_id = EXCLUDED.owner_id, '
            . 'url = EXCLUDED.url, updated_at = now()',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId, 'ti' => $title, 'b' => $body, 'o' => $ownerId, 'u' => $url],
        );

        // Optional dasselbe Dokument für die Hybrid-Suche einbetten (best-effort:
        // Volltext bleibt maßgeblich, ein Embedding-Fehler darf das Indexieren nie
        // brechen). Gesteuert pro Aufruf ($embed) oder global (ai.embed.auto_index).
        if ($this->shouldEmbed($embed)) {
            try {
                $content = trim($title . "\n" . $body);
                $this->embeddings()->index($source, $entityType, $entityId, $content, $ownerId);
            } catch (Throwable) {
            }
        }
    }

    public function remove(string $source, string $entityType, string $entityId): void
    {
        $this->conn()->execute(
            'DELETE FROM search_index WHERE source = :s AND entity_type = :et AND entity_id = :ei',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId],
        );
        // Embedding synchron mit entfernen (best-effort), damit beide Indizes
        // konsistent bleiben.
        try {
            $this->embeddings()->remove($source, $entityType, $entityId);
        } catch (Throwable) {
        }
    }

    public function removeSource(string $source): void
    {
        $this->conn()->execute('DELETE FROM search_index WHERE source = :s', ['s' => $source]);
        try {
            $this->embeddings()->removeSource($source);
        } catch (Throwable) {
        }
    }

    /**
     * Entscheidet, ob beim Indexieren zusätzlich eingebettet wird: expliziter
     * Parameter hat Vorrang, sonst das Setting `ai.embed.auto_index`; in beiden
     * Fällen nur, wenn überhaupt ein Embedding-Provider verfügbar ist.
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
     * Volltextsuche. `$userId === null` = System/Admin (keine Sichtbarkeits-
     * Einschränkung); sonst nur eigene + öffentliche Dokumente.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,title:string,url:?string,rank:float}>
     */
    public function search(string $query, ?string $userId = null, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $scope = $userId === null ? '' : ' AND (owner_id IS NULL OR owner_id = :uid)';
        $params = ['q' => $q, 'l' => $limit];
        if ($userId !== null) {
            $params['uid'] = $userId;
        }

        $rows = $this->conn()->execute(
            "SELECT source, entity_type, entity_id, title, url, "
            . "ts_rank(tsv, websearch_to_tsquery('simple', :q)) AS rank "
            . "FROM search_index "
            . "WHERE tsv @@ websearch_to_tsquery('simple', :q)" . $scope . ' '
            . 'ORDER BY rank DESC, updated_at DESC LIMIT :l',
            $params,
        )->fetchAll('assoc');

        return array_map(static fn (array $r): array => [
            'source' => (string)$r['source'],
            'entity_type' => (string)$r['entity_type'],
            'entity_id' => (string)$r['entity_id'],
            'title' => (string)$r['title'],
            'url' => $r['url'] !== null ? (string)$r['url'] : null,
            'rank' => (float)$r['rank'],
        ], $rows);
    }

    /**
     * **Hybride Suche**: fusioniert Volltext (`tsvector`/`ts_rank`) und semantische
     * Vektor-Ähnlichkeit (pgvector-Embeddings) über **Reciprocal Rank Fusion** (RRF).
     * Vereint lexikalische Präzision (exakte Begriffe) mit semantischem Recall
     * (Bedeutung/Synonyme). Ist kein Embedding-Provider konfiguriert (oder schlägt
     * die Einbettung fehl), degradiert die Methode sauber auf reine Volltextsuche.
     *
     * Sichtbarkeit (Eigentümer) wird in beiden Hälften gleich gefiltert.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,title:string,url:?string,score:float}>
     */
    public function hybrid(string $query, ?string $userId = null, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        // Breitere Kandidatenmengen je Seite -> bessere Fusion, dann auf $limit kürzen.
        $candidates = max($limit, 50);
        $fts = $this->search($q, $userId, $candidates);

        $vec = [];
        try {
            $emb = $this->embeddings();
            if ($emb->available()) {
                $vec = $emb->semantic($q, $userId, $candidates);
            }
        } catch (Throwable) {
            $vec = []; // AI/Embeddings nicht verfügbar -> reine Volltextsuche
        }

        return $this->fuse($fts, $vec, $limit);
    }

    /**
     * Reciprocal Rank Fusion zweier gerankter Trefferlisten über den Schlüssel
     * (source, entity_type, entity_id). Score = Σ 1/(k + Rang); k=60 (Standard).
     *
     * @param list<array<string,mixed>> $fts Volltext-Treffer (mit title/url)
     * @param list<array<string,mixed>> $vec Vektor-Treffer (mit content)
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
                    // Erste Quelle gewinnt die Metadaten — FTS (mit title/url) wird
                    // zuerst eingespeist, daher bevorzugt; reine Vektor-Treffer
                    // fallen auf einen Inhalts-Ausschnitt als Titel zurück.
                    $meta[$key] = [
                        'source' => (string)$r['source'],
                        'entity_type' => (string)$r['entity_type'],
                        'entity_id' => (string)$r['entity_id'],
                        'title' => (string)($r['title'] ?? mb_substr((string)($r['content'] ?? ''), 0, 120)),
                        'url' => isset($r['url']) && $r['url'] !== null ? (string)$r['url'] : null,
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
     * Stößt den vollständigen Neuaufbau über die Modul-Indexer an
     * (`core.collector.search`). Gibt die Anzahl angesprochener Indexer zurück.
     */
    public function reindexAll(): int
    {
        $runtime = $this->runtime ??= new ContributionRuntime();
        $count = 0;
        try {
            foreach ($runtime->collectors(self::COLLECTOR) as $contrib) {
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
