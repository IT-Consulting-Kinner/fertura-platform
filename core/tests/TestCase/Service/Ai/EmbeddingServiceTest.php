<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Ai;

use App\Service\Ai\AiGateway;
use App\Service\Ai\EmbeddingService;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test des Embedding-Stores + semantischer Suche (P11, pgvector). Das AI-Gateway
 * wird durch einen deterministischen Stub ersetzt (kein realer LLM-Aufruf).
 */
class EmbeddingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        ConnectionManager::get('default')->execute("DELETE FROM embeddings WHERE source = 'zztest'");
    }

    private function service(): EmbeddingService
    {
        $stub = new class extends AiGateway {
            public function embed(string $text): array
            {
                // Deterministischer 1536-dim Vektor (zwei Komponenten -> kollisionsarm).
                $v = array_fill(0, 1536, 0.0);
                $v[abs(crc32($text)) % 1536] = 1.0;
                $v[(abs(crc32($text)) >> 8) % 1536] += 1.0;

                return $v;
            }
        };

        return new EmbeddingService($stub);
    }

    public function testIndexAndSemanticSearch(): void
    {
        $svc = $this->service();
        $svc->index('zztest', 'doc', 'a', 'Apfel Banane Obst');
        $svc->index('zztest', 'doc', 'b', 'Auto Motor Technik');

        $res = $svc->semantic('Apfel Banane Obst', null, 5);
        $this->assertNotEmpty($res);
        $this->assertSame('a', $res[0]['entity_id'], 'identischer Inhalt -> höchste Ähnlichkeit');
        $this->assertGreaterThan(0.99, $res[0]['score']);
    }

    public function testWrongDimensionRejected(): void
    {
        $stub = new class extends AiGateway {
            public function embed(string $text): array
            {
                return array_fill(0, 768, 0.1); // z. B. Google-Modell -> falsche Dimension
            }
        };
        $this->expectException(\App\Service\Ai\AiException::class);
        $this->expectExceptionMessageMatches('/Dimension/');
        (new \App\Service\Ai\EmbeddingService($stub))->index('zztest', 'doc', 'x', 'Inhalt');
    }

    public function testOwnerScoping(): void
    {
        $svc = $this->service();
        $owner = (string)ConnectionManager::get('default')->execute('SELECT core.uuid_generate_v7() AS id')->fetch('assoc')['id'];
        $other = (string)ConnectionManager::get('default')->execute('SELECT core.uuid_generate_v7() AS id')->fetch('assoc')['id'];
        $svc->index('zztest', 'doc', 'mine', 'Geheim Text', $owner);
        $svc->index('zztest', 'doc', 'theirs', 'Geheim Text', $other);

        $ids = array_column($svc->semantic('Geheim Text', $owner, 10), 'entity_id');
        $this->assertContains('mine', $ids);
        $this->assertNotContains('theirs', $ids);
    }
}
