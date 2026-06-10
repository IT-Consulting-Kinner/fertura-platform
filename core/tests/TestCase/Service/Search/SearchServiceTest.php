<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Search;

use App\Service\Ai\EmbeddingService;
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

    public function testHybridFusesFtsAndVectorAndDegrades(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', '1', 'Quartalsbericht Finanzen', 'Umsatz');
        $s->index('zztest', 'doc', '2', 'Reisekostenrichtlinie', 'Spesen');

        // Kein Embedding-Provider -> Hybrid degradiert auf reine Volltextsuche.
        $ftsOnly = $s->hybrid('Quartalsbericht', null);
        $this->assertSame('1', $ftsOnly[0]['entity_id']);
        $this->assertArrayHasKey('score', $ftsOnly[0]);

        // Mit (gestubbtem) Embedding-Dienst: FTS findet nur doc 1, der Vektor-Teil
        // bringt doc 2 (verwandt) und doc 3 (nur semantisch, nicht im FTS-Index).
        $fakeEmbeddings = new class extends EmbeddingService {
            public function __construct()
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function semantic(string $query, ?string $userId = null, int $limit = 10): array
            {
                return [
                    ['source' => 'zztest', 'entity_type' => 'doc', 'entity_id' => '2', 'content' => 'verwandt', 'score' => 0.9],
                    ['source' => 'zztest', 'entity_type' => 'doc', 'entity_id' => '3', 'content' => 'Nur semantisch relevant', 'score' => 0.8],
                ];
            }
        };
        $hybrid = new SearchService(null, $fakeEmbeddings);
        $hybrid->index('zztest', 'doc', '1', 'Quartalsbericht Finanzen', 'Umsatz');
        $hybrid->index('zztest', 'doc', '2', 'Reisekostenrichtlinie', 'Spesen');

        $res = $hybrid->hybrid('Quartalsbericht', null, 10);
        $ids = array_column($res, 'entity_id');
        $this->assertContains('1', $ids, 'Volltext-Treffer enthalten');
        $this->assertContains('2', $ids, 'Vektor-Treffer enthalten');
        $this->assertContains('3', $ids, 'rein semantischer Treffer (nicht im FTS-Index) enthalten');

        // Rein semantischer Treffer (doc 3) zieht den Titel aus dem Inhalt.
        $three = array_values(array_filter($res, static fn ($r) => $r['entity_id'] === '3'))[0];
        $this->assertSame('Nur semantisch relevant', $three['title']);
        $this->assertNull($three['url']);
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
