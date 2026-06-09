<?php
declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Module\ContributionRuntime;
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

    public function __construct(private ?ContributionRuntime $runtime = null)
    {
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
    ): void {
        $this->conn()->execute(
            'INSERT INTO search_index (source, entity_type, entity_id, title, body, owner_id, url) '
            . 'VALUES (:s, :et, :ei, :ti, :b, :o, :u) '
            . 'ON CONFLICT (source, entity_type, entity_id) DO UPDATE SET '
            . 'title = EXCLUDED.title, body = EXCLUDED.body, owner_id = EXCLUDED.owner_id, '
            . 'url = EXCLUDED.url, updated_at = now()',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId, 'ti' => $title, 'b' => $body, 'o' => $ownerId, 'u' => $url],
        );
    }

    public function remove(string $source, string $entityType, string $entityId): void
    {
        $this->conn()->execute(
            'DELETE FROM search_index WHERE source = :s AND entity_type = :et AND entity_id = :ei',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId],
        );
    }

    public function removeSource(string $source): void
    {
        $this->conn()->execute('DELETE FROM search_index WHERE source = :s', ['s' => $source]);
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
