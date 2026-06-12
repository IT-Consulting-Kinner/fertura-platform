<?php
declare(strict_types=1);

namespace ZztestWeb\Api;

/**
 * Fixture API handler for a PUBLIC module endpoint (no Core user token): the Core
 * passes the request through (incl. headers) and the module owns its own auth.
 */
final class StatusEndpoint
{
    /**
     * @param array<string, mixed> $request
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(array $request): array
    {
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'auth' => 'public',
                // Demonstrates that the module can read its own token header for
                // self-managed auth (Decision D1 = pass-through).
                'saw_module_token' => isset($headers['X-Module-Token']),
            ],
        ];
    }
}
