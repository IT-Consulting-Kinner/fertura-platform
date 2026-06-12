<?php
declare(strict_types=1);

namespace ZztestWeb\Api;

/**
 * Fixture API handler for a `user`-auth module endpoint: the Core requires a valid
 * Bearer token (the request never reaches here without one).
 */
final class SecureEndpoint
{
    /**
     * @param array<string, mixed> $request
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(array $request): array
    {
        return ['status' => 200, 'body' => ['ok' => true, 'auth' => 'user']];
    }
}
