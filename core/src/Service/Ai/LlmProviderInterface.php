<?php
declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Vertrag für einen LLM-Provider (P11). Konkrete Provider (OpenAI/Anthropic/
 * xAI/Google) kapseln das jeweilige HTTP-API hinter einer einheitlichen
 * Schnittstelle; der Netzzugriff läuft über den gehärteten Egress (P01).
 */
interface LlmProviderInterface
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts
     * @return array{text:string, raw:array<string,mixed>}
     */
    public function chat(array $messages, array $opts = []): array;

    /**
     * @return list<float> Embedding-Vektor
     */
    public function embed(string $text, ?string $model = null): array;
}
