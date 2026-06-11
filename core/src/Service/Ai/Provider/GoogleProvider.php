<?php
declare(strict_types=1);

namespace App\Service\Ai\Provider;

use App\Service\Ai\AiException;
use App\Service\Ai\LlmProviderInterface;
use App\Service\Http\EgressClient;

/**
 * Google Gemini provider (P11). Roles are mapped to `user`/`model`, system
 * messages to `systemInstruction`; the key is passed as a query parameter.
 */
class GoogleProvider implements LlmProviderInterface
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
        $contents = [];
        $system = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= $m['content'] . "\n";
                continue;
            }
            $contents[] = [
                'role' => ($m['role'] ?? '') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string)$m['content']]],
            ];
        }
        $body = ['contents' => $contents];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => trim($system)]]];
        }
        $model = $this->model ?: 'gemini-1.5-flash';
        $url = $this->endpoint . '/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($this->apiKey);

        $resp = $this->egress->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'data' => (string)json_encode($body),
        ]);
        if (!$resp->isSuccess()) {
            throw new AiException('Google HTTP ' . $resp->statusCode . ': ' . mb_substr($resp->body, 0, 300));
        }
        $raw = (array)$resp->json();

        return ['text' => (string)($raw['candidates'][0]['content']['parts'][0]['text'] ?? ''), 'raw' => $raw];
    }

    public function embed(string $text, ?string $model = null): array
    {
        $model = $model ?: 'text-embedding-004';
        $url = $this->endpoint . '/models/' . rawurlencode($model) . ':embedContent?key=' . urlencode($this->apiKey);
        $resp = $this->egress->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'data' => (string)json_encode(['content' => ['parts' => [['text' => $text]]]]),
        ]);
        if (!$resp->isSuccess()) {
            throw new AiException('Google-Embedding HTTP ' . $resp->statusCode);
        }
        $raw = (array)$resp->json();

        return array_map('floatval', (array)($raw['embedding']['values'] ?? []));
    }
}
