<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Search;

use App\Service\Search\SearchService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test der Volltextsuche (P10): Indexierung, Ranking, Sichtbarkeits-Filter
 * (Eigentümer), Entfernen.
 */
class SearchServiceTest extends TestCase
{
    private string $owner;
    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->owner = $this->uuid();
        $this->other = $this->uuid();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM search_index WHERE source = 'zztest'");
    }

    private function uuid(): string
    {
        return (string)ConnectionManager::get('default')
            ->execute('SELECT core.uuid_generate_v7() AS id')->fetch('assoc')['id'];
    }

    public function testIndexAndRankedSearch(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', '1', 'Quartalsbericht Finanzen', 'Umsatz und Gewinn im Quartal');
        $s->index('zztest', 'doc', '2', 'Reisekostenrichtlinie', 'Spesen und Erstattung');

        $results = $s->search('Quartalsbericht');
        $this->assertNotEmpty($results);
        $this->assertSame('1', $results[0]['entity_id']);

        // Mehrwort/websearch-Syntax.
        $this->assertNotEmpty($s->search('Umsatz Gewinn'));
        $this->assertSame([], $s->search('Nichtvorhandenerbegriff'));
    }

    public function testOwnerVisibilityScoping(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', 'pub', 'Öffentliche Notiz Apfel', '', null);
        $s->index('zztest', 'doc', 'mine', 'Private Notiz Apfel', '', $this->owner);
        $s->index('zztest', 'doc', 'theirs', 'Fremde Notiz Apfel', '', $this->other);

        $ids = array_column($s->search('Apfel', $this->owner), 'entity_id');
        sort($ids);
        $this->assertSame(['mine', 'pub'], $ids, 'nur eigene + öffentliche Dokumente');

        // Ohne Benutzer (System) -> alle.
        $this->assertCount(3, $s->search('Apfel', null));
    }

    public function testUpdateAndRemove(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', 'x', 'Birne', '');
        $this->assertCount(1, $s->search('Birne'));

        // Upsert ersetzt den Inhalt.
        $s->index('zztest', 'doc', 'x', 'Kirsche', '');
        $this->assertSame([], $s->search('Birne'));
        $this->assertCount(1, $s->search('Kirsche'));

        $s->remove('zztest', 'doc', 'x');
        $this->assertSame([], $s->search('Kirsche'));
    }
}
