<?php
declare(strict_types=1);

namespace App\Service\Search;

/**
 * Contract for a module search indexer (program tier 1, P10; core contract
 * `core.collector.search`). `reindex()` (re)builds the module's searchable
 * documents via {@see SearchService::index()}. Incremental maintenance happens
 * additionally, directly on data changes.
 */
interface SearchIndexerInterface
{
    public function reindex(): void;
}
