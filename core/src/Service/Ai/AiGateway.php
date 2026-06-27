<?php
declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Ai\Provider\AnthropicProvider;
use App\Service\Ai\Provider\GoogleProvider;
use App\Service\Ai\Provider\OpenAiProvider;
use App\Service\Ai\Provider\XaiProvider;
use App\Service\Http\EgressClient;
use App\Service\Settings\SettingsManager;
use Throwable;

/**
 * Provider-agnostic LLM gateway (program Tier-2, P11).
 *
 * Selects a provider based on configuration (`core.ai.*`) — **OpenAI, Anthropic,
 * xAI/Grok, Google/Gemini** — and calls it through the hardened egress (P01).
 * **API keys are kept out-of-band** via env (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
 * `XAI_API_KEY`, `GOOGLE_API_KEY`), never in the DB. Without a provider/key it is
 * disabled (with a clear {@see AiException}). Exposed to modules as the `core.ai.*`
 * capability.
 *
 * LLM calls get their **own** egress timeout (`core.ai.timeout_seconds`, default
 * 60s) — completions routinely exceed the shared 10s egress default, at which they
 * would otherwise fail silently. Keeping it separate avoids dilating the egress
 * timeout for webhooks/OIDC/marketplace.
 */
class AiGateway
{
    public function __construct(
        private ?EgressClient $egress = null,
        private ?SettingsManager $settings = null,
    ) {
        // Build the egress with the AI-specific timeout (config wins over the
        // core.http.egress.timeout_seconds setting). An injected egress (tests)
        // keeps its own timeout.
        $this->egress ??= new EgressClient(null, ['timeout_seconds' => $this->aiTimeoutSeconds()]);
    }

    private function settings(): SettingsManager
    {
        return $this->settings ??= new SettingsManager();
    }

    /** Dedicated egress timeout (seconds) for LLM calls; default 60, fail-safe. */
    private function aiTimeoutSeconds(): int
    {
        try {
            $v = (int)$this->settings()->get('core', 'ai.timeout_seconds', 60);

            return $v > 0 ? $v : 60;
        } catch (Throwable) {
            return 60;
        }
    }

    public function enabled(): bool
    {
        $name = $this->str('ai.chat.provider');

        return $name !== '' && $this->apiKey($name) !== '';
    }

    /** Is an embedding provider configured (for semantic/hybrid search)? */
    public function embedEnabled(): bool
    {
        $name = $this->str('ai.embed.provider');

        return $name !== '' && $this->apiKey($name) !== '';
    }

    public function complete(string $prompt, array $opts = []): string
    {
        return $this->chatMessages([['role' => 'user', 'content' => $prompt]], $opts)['text'];
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed> $opts
     * @return array{text:string, raw:array<string,mixed>}
     */
    public function chatMessages(array $messages, array $opts = []): array
    {
        return $this->provider($this->str('ai.chat.provider'), $this->str('ai.chat.model'))->chat($messages, $opts);
    }

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $model = $this->str('ai.embed.model');

        return $this->provider($this->str('ai.embed.provider'), $model)->embed($text, $model !== '' ? $model : null);
    }

    private function provider(string $name, string $model): LlmProviderInterface
    {
        $key = $this->apiKey($name);
        if ($name === '' || $key === '') {
            throw new AiException("AI nicht konfiguriert: Provider/Schlüssel für '$name' fehlt.");
        }
        $endpoint = $this->endpoint($name);

        return match ($name) {
            'openai' => new OpenAiProvider($this->egress, $key, $endpoint, $model),
            'xai' => new XaiProvider($this->egress, $key, $endpoint, $model),
            'anthropic' => new AnthropicProvider($this->egress, $key, $endpoint, $model),
            'google' => new GoogleProvider($this->egress, $key, $endpoint, $model),
            default => throw new AiException("Unbekannter AI-Provider: $name"),
        };
    }

    private function endpoint(string $name): string
    {
        $custom = $this->str('ai.' . $name . '.endpoint');
        if ($custom !== '') {
            // Only allow the override over https — otherwise the Authorization
            // bearer key would travel over the wire in plaintext. The actual
            // request goes through the SSRF-hardened egress in any case.
            if (strtolower((string)parse_url($custom, PHP_URL_SCHEME)) !== 'https') {
                throw new AiException(
                    "AI-Endpoint-Override für '$name' muss https sein (Schutz des API-Schlüssels): $custom",
                );
            }

            return rtrim($custom, '/');
        }

        return match ($name) {
            'openai' => 'https://api.openai.com/v1',
            'xai' => 'https://api.x.ai/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'google' => 'https://generativelanguage.googleapis.com/v1beta',
            default => '',
        };
    }

    private function apiKey(string $name): string
    {
        return $name === '' ? '' : (string)(getenv(strtoupper($name) . '_API_KEY') ?: '');
    }

    private function str(string $key): string
    {
        try {
            return (string)($this->settings()->get('core', $key, '') ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
