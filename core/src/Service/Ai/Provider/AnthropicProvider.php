<?php
declare(strict_types=1);

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiException;
use App\Service\Ai\LlmProviderInterface;
use App\Service\Http\EgressClient;

/**
 * Anthropic/Claude provider (P11). System messages are passed via the `system`
 * field; Anthropic does not offer embeddings.
 */
class AnthropicProvider implements LlmProviderInterface
{
    public function __construct(
        private EgressClient $egress,
        private string $apiKey,
        private string $endpoint,
        private string $model,
    ) {
    }

    public function chat(array $messages, array $opts = []): array
    {
        $system = '';
        $msgs = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
                continue;
            }
            $msgs[] = ['role' => ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'content' => (string)$m['content']];
        }
        $body = [
            'model' => $this->model ?: 'claude-3-5-sonnet-latest',
            'max_tokens' => (int)($opts['max_tokens'] ?? 1024),
            'messages' => $msgs,
        ];
        if ($system !== '') {
            $body['system'] = trim($system);
        }
        if (isset($opts['temperature'])) {
            $body['temperature'] = $opts['temperature'];
        }

        $resp = $this->egress->request('POST', $this->endpoint . '/messages', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'data' => (string)json_encode($body),
        ]);
        if (!$resp->isSuccess()) {
            throw new AiException('Anthropic HTTP ' . $resp->statusCode . ': ' . mb_substr($resp->body, 0, 300));
        }
        $raw = (array)$resp->json();

        return ['text' => (string)($raw['content'][0]['text'] ?? ''), 'raw' => $raw];
    }

    public function embed(string $text, ?string $model = null): array
    {
        throw new AiException('Anthropic bietet keine Embeddings — anderen Embedding-Provider konfigurieren.');
    }
}
