<?php
declare(strict_types=1);

namespace App\Service\Ai;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Semantischer Index (Programm Tier-2, P11): bettet Inhalte über das
 * {@see AiGateway} ein und legt sie in `core.embeddings` (pgvector) ab;
 * {@see semantic()} sucht per Cosine-Ähnlichkeit — sichtbarkeits-gefiltert über
 * den Eigentümer (wie die Volltextsuche P10).
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

    public function index(string $source, string $entityType, string $entityId, string $content, ?string $ownerId = null): void
    {
        $vector = $this->literal($this->ai->embed($content));
        $this->conn()->execute(
            'INSERT INTO embeddings (source, entity_type, entity_id, owner_id, content, embedding) '
            . 'VALUES (:s, :et, :ei, :o, :c, :v::vector) '
            . 'ON CONFLICT (source, entity_type, entity_id) DO UPDATE SET '
            . 'owner_id = EXCLUDED.owner_id, content = EXCLUDED.content, embedding = EXCLUDED.embedding',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId, 'o' => $ownerId, 'c' => $content, 'v' => $vector],
        );
    }

    public function remove(string $source, string $entityType, string $entityId): void
    {
        $this->conn()->execute(
            'DELETE FROM embeddings WHERE source = :s AND entity_type = :et AND entity_id = :ei',
            ['s' => $source, 'et' => $entityType, 'ei' => $entityId],
        );
    }

    /**
     * Semantische Suche. `$userId === null` = System (alles); sonst nur eigene +
     * öffentliche Dokumente.
     *
     * @return list<array{source:string,entity_type:string,entity_id:string,content:string,score:float}>
     */
    public function semantic(string $query, ?string $userId = null, int $limit = 10): array
    {
        $vector = $this->literal($this->ai->embed($query));
        $scope = $userId === null ? '' : ' AND (owner_id IS NULL OR owner_id = :uid)';
        $params = ['v' => $vector, 'l' => $limit];
        if ($userId !== null) {
            $params['uid'] = $userId;
        }

        $rows = $this->conn()->execute(
            'SELECT source, entity_type, entity_id, content, '
            . '(1 - (embedding <=> :v::vector)) AS score '
            . 'FROM embeddings WHERE true' . $scope . ' '
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
    /** Erwartete Embedding-Dimension (= Spaltentyp `vector(1536)`). */
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

            // Locale-UNABHÄNGIG formatieren: `number_format` mit explizitem '.'
            // als Dezimaltrenner (kein `LC_NUMERIC`-Komma, das das `::vector`-
            // Literal zerstören würde) und Festkomma (kein Exponent). 12 Stellen
            // erfassen die Float-Genauigkeit ohne Repräsentationsrauschen; Nullen
            // werden abgeschnitten. Vgl. `sprintf('%f')` wäre `LC_NUMERIC`-anfällig.
            return rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.');
        }, $floats)) . ']';
    }
}
