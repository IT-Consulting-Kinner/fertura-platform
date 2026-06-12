<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Search;

use App\Service\Ai\EmbeddingService;
use App\Service\Cache\CacheStore;
use App\Service\Search\SearchService;
use App\Service\Settings\SettingsManager;
use App\Service\Tenant\TenantService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test of full-text search (P10): indexing, ranking, visibility filter
 * (owner), removal.
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

        // Multi-word / websearch syntax.
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

        // Without a user (system) -> all.
        $this->assertCount(3, $s->search('Apfel', null));
    }

    public function testHybridFusesFtsAndVectorAndDegrades(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', '1', 'Quartalsbericht Finanzen', 'Umsatz');
        $s->index('zztest', 'doc', '2', 'Reisekostenrichtlinie', 'Spesen');

        // No embedding provider -> hybrid degrades to pure full-text search.
        $ftsOnly = $s->hybrid('Quartalsbericht', null);
        $this->assertSame('1', $ftsOnly[0]['entity_id']);
        $this->assertArrayHasKey('score', $ftsOnly[0]);

        // With a (stubbed) embedding service: FTS finds only doc 1, the vector part
        // brings doc 2 (related) and doc 3 (purely semantic, not in the FTS index).
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

        // A purely semantic hit (doc 3) derives its title from the content.
        $three = array_values(array_filter($res, static fn($r) => $r['entity_id'] === '3'))[0];
        $this->assertSame('Nur semantisch relevant', $three['title']);
        $this->assertNull($three['url']);
    }

    public function testAutoEmbedOnIndexAndRemove(): void
    {
        // Embedding service that records calls (no real LLM call).
        $emb = new class extends EmbeddingService {
            /**
             * @var list<array{0:string,1:string,2:string}>
             */
            public array $indexed = [];
            /**
             * @var list<array{0:string,1:string,2:string}>
             */
            public array $removed = [];

            public function __construct()
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function index(string $source, string $entityType, string $entityId, string $content, ?string $ownerId = null, ?string $tenantId = null): void
            {
                $this->indexed[] = [$source, $entityType, $entityId];
            }

            public function remove(string $source, string $entityType, string $entityId): void
            {
                $this->removed[] = [$source, $entityType, $entityId];
            }
        };
        $s = new SearchService(null, $emb);

        // explicit embed:true -> FTS + embedding
        $s->index('zztest', 'doc', 'e1', 'Titel', 'Inhalt', null, null, true);
        $this->assertSame([['zztest', 'doc', 'e1']], $emb->indexed);

        // explicit embed:false -> FTS only, no embedding
        $s->index('zztest', 'doc', 'e2', 'Titel2', '', null, null, false);
        $this->assertCount(1, $emb->indexed, 'embed:false unterdrückt das Embedding');

        // remove cleans up both indexes
        $s->remove('zztest', 'doc', 'e1');
        $this->assertSame([['zztest', 'doc', 'e1']], $emb->removed);
    }

    public function testBackfillEmbeddings(): void
    {
        $emb = new class extends EmbeddingService {
            /**
             * @var list<string>
             */
            public array $indexed = [];

            public function __construct()
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function index(string $source, string $entityType, string $entityId, string $content, ?string $ownerId = null, ?string $tenantId = null): void
            {
                $this->indexed[] = $entityId;
            }
        };
        $s = new SearchService(null, $emb);
        // Without auto_index -> only full text indexed, no embeddings.
        $s->index('zztest', 'doc', 'b1', 'Backfill Eins', 'Inhalt eins');
        $s->index('zztest', 'doc', 'b2', 'Backfill Zwei', 'Inhalt zwei');

        $n = $s->backfillEmbeddings(100);
        $this->assertGreaterThanOrEqual(2, $n);
        $this->assertContains('b1', $emb->indexed);
        $this->assertContains('b2', $emb->indexed);
    }

    public function testTenantScopingIsolatesSearch(): void
    {
        $conn = ConnectionManager::get('default');
        $default = TenantService::DEFAULT_TENANT_ID;
        $s = new SearchService();
        $conn->begin();
        try {
            $tenantB = (new TenantService())->create('zztest-s2', 'S2')['id'];

            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $default]);
            $s->index('zztest', 'doc', 't1', 'Mandantenwortzwei Alpha');

            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $tenantB]);
            $s->index('zztest', 'doc', 't2', 'Mandantenwortzwei Beta');

            // Tenant B sees only its own hit.
            $idsB = array_column($s->search('Mandantenwortzwei'), 'entity_id');
            $this->assertContains('t2', $idsB);
            $this->assertNotContains('t1', $idsB, 'Mandant B sieht den Default-Mandant NICHT (kein Leck)');

            // Default tenant sees only its own hit.
            $conn->execute("SELECT set_config('app.current_tenant_id', :t, true)", ['t' => $default]);
            $idsD = array_column($s->search('Mandantenwortzwei'), 'entity_id');
            $this->assertContains('t1', $idsD);
            $this->assertNotContains('t2', $idsD);
        } finally {
            $conn->rollback(); // resets set_config (tx-local) and discards the test indexes
        }
    }

    public function testLanguageStemming(): void
    {
        $conn = ConnectionManager::get('default');
        $sm = new SettingsManager();
        $s = new SearchService();
        $s->index('zztest', 'doc', 'lang1', 'Bücher und Zeitungen');

        try {
            // With German config, the inflected form „Büchern" finds the document „Bücher".
            $sm->set('core', 'search.text_config', 'german');
            $this->assertContains(
                'lang1',
                array_column($s->search('Büchern'), 'entity_id'),
                'Stemming: Büchern findet Bücher',
            );

            // Without stemming (simple), the other inflected form does not match.
            $sm->set('core', 'search.text_config', 'simple');
            $this->assertNotContains(
                'lang1',
                array_column($s->search('Büchern'), 'entity_id'),
                'simple: keine Stamm-Übereinstimmung',
            );
        } finally {
            $conn->execute("DELETE FROM settings WHERE namespace = 'core' AND config_key = 'search.text_config'");
            (new CacheStore('_app_settings_'))->clear();
        }
    }

    public function testUpdateAndRemove(): void
    {
        $s = new SearchService();
        $s->index('zztest', 'doc', 'x', 'Birne', '');
        $this->assertCount(1, $s->search('Birne'));

        // Upsert replaces the content.
        $s->index('zztest', 'doc', 'x', 'Kirsche', '');
        $this->assertSame([], $s->search('Birne'));
        $this->assertCount(1, $s->search('Kirsche'));

        $s->remove('zztest', 'doc', 'x');
        $this->assertSame([], $s->search('Kirsche'));
    }
}
