<?php
declare(strict_types=1);

namespace App\Service\Ai\Provider;

/**
 * xAI/Grok-Provider (P11): OpenAI-kompatibles API, daher nur abweichende
 * Defaults (Endpoint/Modell werden vom Gateway gesetzt).
 */
class XaiProvider extends OpenAiProvider
{
    protected function defaultChatModel(): string
    {
        return 'grok-2-latest';
    }
}
