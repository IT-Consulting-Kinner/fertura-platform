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
     * @return array{status:int, body:array<string,mixed>, headers:array<string,string>}
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
            // A `public` route may set caching headers (E160) and Retry-After for
            // its own rate-limit backoff (E175). Only allowlisted headers pass
            // through; `Set-Cookie` must NOT.
            'headers' => [
                'Cache-Control' => 'public, max-age=300',
                'ETag' => '"v1"',
                'Retry-After' => '30',
                'Set-Cookie' => 'evil=1',
            ],
        ];
    }
}
