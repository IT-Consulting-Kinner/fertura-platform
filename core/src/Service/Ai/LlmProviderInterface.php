<?php
declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Contract for an LLM provider (P11). Concrete providers (OpenAI/Anthropic/
 * xAI/Google) wrap their respective HTTP API behind a uniform interface;
 * network access goes through the hardened egress (P01).
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
     * @return list<float> Embedding vector
     */
    public function embed(string $text, ?string $model = null): array;
}
