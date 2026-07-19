<?php

declare(strict_types=1);

// Persistent OpenAI-compatible stub LLM for the ki_dienste "local" provider (dev).
// Serves ANY path with a canned chat-completion; GET = health (200). Bound on the
// worker's loopback (see the ai-stub-worker service in docker-compose.yml) because
// outbox ai.complete egress runs in the worker container and ki_dienste's per-tenant
// dev config points at http://127.0.0.1:8199/v1.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'stub' => true]);

    return;
}

$raw = (string)file_get_contents('php://input');
$req = json_decode($raw, true);
$model = is_array($req) && is_string($req['model'] ?? null) ? $req['model'] : 'stub';

$content = '[[STUB-AI]] Klarere, praezisere Formulierung vom lokalen Stub-LLM (Dev).';

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'id' => 'stub-cmpl-1',
    'object' => 'chat.completion',
    'model' => $model,
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => $content],
        'finish_reason' => 'stop',
    ]],
    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 9, 'total_tokens' => 21],
], JSON_UNESCAPED_UNICODE);
