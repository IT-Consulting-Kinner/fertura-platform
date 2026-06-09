<?php
declare(strict_types=1);

namespace App\Service\Search;

/**
 * Vertrag für einen Modul-Suchindexer (Programm Tier-1, P10; Core-Contract
 * `core.collector.search`). `reindex()` legt die durchsuchbaren Dokumente des
 * Moduls über {@see SearchService::index()} (neu) ab. Inkrementelle Pflege
 * erfolgt zusätzlich direkt bei Datenänderungen.
 */
interface SearchIndexerInterface
{
    public function reindex(): void;
}
