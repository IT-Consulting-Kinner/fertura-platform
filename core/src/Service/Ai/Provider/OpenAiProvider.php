<?php
declare(strict_types=1);

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiException;
use App\Service\Ai\LlmProviderInterface;
use App\Service\Http\EgressClient;
use App\Service\Http\EgressResponse;

/**
 * OpenAI provider (P11), and also the base for OpenAI-compatible APIs (e.g. xAI).
 */
class OpenAiProvider implements LlmProviderInterface
{
    public function __construct(
        protected EgressClient $egress,
        protected string $apiKey,
        protected string $endpoint,
        protected string $model,
    ) {
    }

    protected function defaultChatModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function chat(array $messages, array $opts = []): array
    {
        $body = ['model' => $this->model ?: $this->defaultChatModel(), 'messages' => array_values($messages)];
        foreach (['temperature', 'max_tokens', 'top_p'] as $k) {
            if (isset($opts[$k])) {
                $body[$k] = $opts[$k];
            }
        }
        $resp = $this->egress->request('POST', $this->endpoint . '/chat/completions', [
            'headers' => $this->headers(),
            'data' => (string)json_encode($body),
        ]);
        $raw = $this->ok($resp);

        return ['text' => (string)($raw['choices'][0]['message']['content'] ?? ''), 'raw' => $raw];
    }

    public function embed(string $text, ?string $model = null): array
    {
        $resp = $this->egress->request('POST', $this->endpoint . '/embeddings', [
            'headers' => $this->headers(),
            'data' => (string)json_encode(['model' => $model ?: 'text-embedding-3-small', 'input' => $text]),
        ]);
        $raw = $this->ok($resp);

        return array_map('floatval', (array)($raw['data'][0]['embedding'] ?? []));
    }

    /**
     * @return array<string,string>
     */
    protected function headers(): array
    {
        return ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * @return array<string,mixed>
     */
    protected function ok(EgressResponse $resp): array
    {
        if (!$resp->isSuccess()) {
            throw new AiException('AI-Provider HTTP ' . $resp->statusCode . ': ' . mb_substr($resp->body, 0, 300));
        }

        return (array)$resp->json();
    }
}
