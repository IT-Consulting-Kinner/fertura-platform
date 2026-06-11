<?php
declare(strict_types=1);

namespace App\Service\Http;

/**
 * Slim, serializable response of the {@see EgressClient} (decouples callers
 * from the concrete HTTP-client implementation).
 */
final class EgressResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Decodes the body as JSON (associative), or null for invalid JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
