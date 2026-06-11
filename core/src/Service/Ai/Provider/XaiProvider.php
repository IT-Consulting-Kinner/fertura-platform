<?php
declare(strict_types=1);

namespace App\Service\Ai\Provider;

/**
 * xAI/Grok provider (P11): OpenAI-compatible API, hence only the differing
 * defaults (endpoint/model are set by the gateway).
 */
class XaiProvider extends OpenAiProvider
{
    protected function defaultChatModel(): string
    {
        return 'grok-2-latest';
    }
}
