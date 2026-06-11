<?php
declare(strict_types=1);

namespace App\Service\Ai;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Semantic index (program Tier-2, P11): embeds content via the
 * {@see AiGateway} and stores it in `core.embeddings` (pgvector);
 * {@see semantic()} searches by cosine similarity — visibility-filtered by
 * owner (like the full-text search P10).
 */
class EmbeddingService
{
    public function __construct(private ?AiGateway $ai = null)
    {
        $this->ai ??= new AiGateway();
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /** Is semantic search/indexing available (embedding provider configured)? */
    public function available(): bool
    {
        try {
            return $this->ai->embedEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function index(string $source, string $entityType, string $entityId, string $content, ?string $ownerId = null, ?string $tenantId = null): void
    {
        $vector = $this->literal($this->ai->embed($content));
        $this->conn()->execute(
            'INSERT INTO embeddings (tenant_id, source, entity_type, entity_id, owner_id, content, embedding) '
            . 'VALUES (:tid, :s, :et, :ei, :o, :c, :v::vector) '
            . 'ON CONFLICT (tenant_id, source, entity_type, entity_id) DO UPDATE SET '
            . 'owner_id = EXCLUDED.owner_id, content = EXCLUDED.content, embedding = EXCLUDED.embedding',
            ['tid' => $tenantId ?? $this->tenantId(), 's' => $source, 'et' => $entityType, 'ei' => $entityId, 'o' => $ownerId, 'c' => $content, 'v' => $vector],
        );
    }

    public function remove(string $source, string $entityType, string $entityId): void
    {
        $this->conn()->execute(
            'DELETE FROM embeddings WHERE tenant_id = :tid AND source = :s AND entity_type = :et AND entity_id = :ei',
            ['tid' => $this->tenantId(), 's' => $source, 'et' => $entityType, 'ei' => $entityId],
        );
    }

    public function removeSource(string $source): void
    {
        $this->conn()->execute(
            'DELETE FROM embeddings WHERE tenant_id = :tid AND source = :s',
            ['tid' => $this->tenantId(), 's' => $source],
        );
    }

    /** Current tenant from the RLS context (falls back to the default tenant). */
    private function tenantId(): string
    {
        try {
            $row = $this->conn()->execute(
                "SELECT coalesce(nullif(current_setting('app.current_tenant_id', true), ''), :d) AS t",
                ['d' => \App\Service\Tenant\TenantService::DEFAULT_TENANT_ID],
            )->fetch('assoc');

            return (string)($row['t'] ?? \App\Service\Tenant\TenantService::DEFAULT_TENANT_ID);
        } catch (\Throwable) {
            return \App\Service\Tenant\TenantService::DEFAULT_TENANT_ID;
        }
    }

    /**
     * Semantic search. `$userId === null` = system (everything); otherwise only
     * the user's own + public documents.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,content:string,score:float}>
     */
    public function semantic(string $query, ?string $userId = null, int $limit = 10): array
    {
        $vector = $this->literal($this->ai->embed($query));
        // Tenant-scoped + owner visibility (own + public within the tenant).
        $scope = $userId === null ? '' : ' AND (owner_id IS NULL OR owner_id = :uid)';
        $params = ['v' => $vector, 'l' => $limit, 'tid' => $this->tenantId()];
        if ($userId !== null) {
            $params['uid'] = $userId;
        }

        $rows = $this->conn()->execute(
            'SELECT source, entity_type, entity_id, content, '
            . '(1 - (embedding <=> :v::vector)) AS score '
            . 'FROM embeddings WHERE tenant_id = :tid' . $scope . ' '
            . 'ORDER BY embedding <=> :v::vector LIMIT :l',
            $params,
        )->fetchAll('assoc');

        return array_map(static fn (array $r): array => [
            'source' => (string)$r['source'],
            'entity_type' => (string)$r['entity_type'],
            'entity_id' => (string)$r['entity_id'],
            'content' => (string)$r['content'],
            'score' => (float)$r['score'],
        ], $rows);
    }

    /**
     * @param list<float> $floats
     */
    /** Expected embedding dimension (= column type `vector(1536)`). */
    private const DIMENSIONS = 1536;

    private function literal(array $floats): string
    {
        if (count($floats) !== self::DIMENSIONS) {
            throw new AiException(sprintf(
                'Embedding-Dimension %d passt nicht zur erwarteten %d — Embedding-Modell prüfen '
                . '(z. B. OpenAI text-embedding-3-small).',
                count($floats),
                self::DIMENSIONS,
            ));
        }

        return '[' . implode(',', array_map(static function ($f): string {
            $v = (float)$f;
            if (!is_finite($v)) {
                throw new AiException('Embedding enthält einen nicht-endlichen Wert.');
            }

            // Format locale-INDEPENDENTLY: `number_format` with an explicit '.'
            // as the decimal separator (no `LC_NUMERIC` comma that would break the
            // `::vector` literal) and fixed-point notation (no exponent). 12 digits
            // capture the float precision without representation noise; trailing
            // zeros are trimmed. By contrast, `sprintf('%f')` would be `LC_NUMERIC`-sensitive.
            return rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.');
        }, $floats)) . ']';
    }
}
