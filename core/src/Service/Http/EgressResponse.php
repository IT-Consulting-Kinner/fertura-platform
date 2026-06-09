<?php
declare(strict_types=1);

namespace App\Service\Http;

/**
 * Schmale, serialisierbare Antwort des {@see EgressClient} (entkoppelt Aufrufer
 * von der konkreten HTTP-Client-Implementierung).
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
     * Dekodiert den Body als JSON (assoziativ) oder null bei ungültigem JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }
}
