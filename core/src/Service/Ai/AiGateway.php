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
 * Provider-agnostisches LLM-Gateway (Programm Tier-2, P11).
 *
 * Wählt nach Konfiguration (`core.ai.*`) einen Provider — **OpenAI, Anthropic,
 * xAI/Grok, Google/Gemini** — und ruft ihn über den gehärteten Egress (P01).
 * **API-Schlüssel out-of-band** über Env (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
 * `XAI_API_KEY`, `GOOGLE_API_KEY`), nie in der DB. Ohne Provider/Schlüssel →
 * deaktiviert (klare {@see AiException}). Modulen als Capability `core.ai.*`
 * angeboten.
 */
class AiGateway
{
    public function __construct(
        private ?EgressClient $egress = null,
        private ?SettingsManager $settings = null,
    ) {
        $this->egress ??= new EgressClient();
        $this->settings ??= new SettingsManager();
    }

    public function enabled(): bool
    {
        $name = $this->str('ai.chat.provider');

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
            return $custom;
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
            return (string)($this->settings->get('core', $key, '') ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
