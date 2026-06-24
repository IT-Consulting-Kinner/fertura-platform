<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Ai;

use App\Service\Ai\AiException;
use App\Service\Ai\AiGateway;
use App\Service\Ai\Provider\AnthropicProvider;
use App\Service\Ai\Provider\GoogleProvider;
use App\Service\Ai\Provider\OpenAiProvider;
use App\Service\Ai\Provider\XaiProvider;
use App\Service\Http\EgressClient;
use App\Service\Http\EgressResponse;
use App\Service\Settings\SettingsManager;
use Cake\TestSuite\TestCase;

/**
 * Tests the LLM providers (P11): request construction + response parsing per
 * provider against an egress stub (no real API traffic), plus gateway disabling.
 */
class AiProviderTest extends TestCase
{
    /** @param array<string,mixed> $json */
    private function egress(array $json): FakeEgress
    {
        return new FakeEgress(200, (string)json_encode($json));
    }

    public function testOpenAiChat(): void
    {
        $e = $this->egress(['choices' => [['message' => ['content' => 'Hallo']]]]);
        $p = new OpenAiProvider($e, 'sk-key', 'https://api.openai.com/v1', 'gpt-4o-mini');
        $r = $p->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hallo', $r['text']);
        $this->assertStringContainsString('/chat/completions', $e->calls[0]['url']);
        $this->assertSame('Bearer sk-key', $e->calls[0]['options']['headers']['Authorization']);
        $body = json_decode($e->calls[0]['options']['data'], true);
        $this->assertSame('gpt-4o-mini', $body['model']);
        $this->assertSame('Hi', $body['messages'][0]['content']);
    }

    public function testXaiDefaultsToGrok(): void
    {
        $e = $this->egress(['choices' => [['message' => ['content' => 'yo']]]]);
        (new XaiProvider($e, 'k', 'https://api.x.ai/v1', ''))->chat([['role' => 'user', 'content' => 'Hi']]);
        $body = json_decode($e->calls[0]['options']['data'], true);
        $this->assertSame('grok-2-latest', $body['model']);
    }

    public function testAnthropicChatAndSystem(): void
    {
        $e = $this->egress(['content' => [['text' => 'Claude sagt hallo']]]);
        $p = new AnthropicProvider($e, 'ak', 'https://api.anthropic.com/v1', '');
        $r = $p->chat([['role' => 'system', 'content' => 'Sei knapp.'], ['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Claude sagt hallo', $r['text']);
        $this->assertStringContainsString('/messages', $e->calls[0]['url']);
        $this->assertSame('ak', $e->calls[0]['options']['headers']['x-api-key']);
        $body = json_decode($e->calls[0]['options']['data'], true);
        $this->assertSame('Sei knapp.', $body['system']);
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function testAnthropicEmbedUnsupported(): void
    {
        $this->expectException(AiException::class);
        (new AnthropicProvider($this->egress([]), 'ak', 'https://x', ''))->embed('text');
    }

    public function testGoogleChat(): void
    {
        $e = $this->egress(['candidates' => [['content' => ['parts' => [['text' => 'Gemini antwortet']]]]]]);
        $p = new GoogleProvider($e, 'gk', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-1.5-flash');
        $r = $p->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Gemini antwortet', $r['text']);
        // U2: the key travels in the x-goog-api-key header, never in the URL.
        $this->assertStringContainsString(':generateContent', $e->calls[0]['url']);
        $this->assertStringNotContainsString('key=', $e->calls[0]['url']);
        $this->assertSame('gk', $e->calls[0]['options']['headers']['x-goog-api-key']);
    }

    public function testGatewayDisabledWithoutConfig(): void
    {
        $gateway = new AiGateway();
        $this->assertFalse($gateway->enabled());
        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/nicht konfiguriert/');
        $gateway->complete('Hallo?');
    }

    public function testCustomEndpointOverrideMustBeHttps(): void
    {
        putenv('OPENAI_API_KEY=sk-test');
        try {
            $gateway = new AiGateway($this->egress([]), new FakeSettings('http://internal-proxy/v1'));
            $this->expectException(AiException::class);
            $this->expectExceptionMessageMatches('/https/');
            $gateway->complete('Hi');
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    public function testCustomHttpsEndpointOverrideIsUsedAndTrimmed(): void
    {
        putenv('OPENAI_API_KEY=sk-test');
        try {
            $e = $this->egress(['choices' => [['message' => ['content' => 'ok']]]]);
            $gateway = new AiGateway($e, new FakeSettings('https://my-proxy.example/v1/'));
            $this->assertSame('ok', $gateway->complete('Hi'));
            $this->assertSame('https://my-proxy.example/v1/chat/completions', $e->calls[0]['url']);
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }
}

/** Settings stub: returns the openai provider + a predefined endpoint override. */
class FakeSettings extends SettingsManager
{
    public function __construct(private string $endpoint)
    {
    }

    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'ai.chat.provider' => 'openai',
            'ai.chat.model' => 'gpt-4o-mini',
            'ai.openai.endpoint' => $this->endpoint,
            default => $default,
        };
    }
}

/** Egress stub: records calls, returns a predefined response. */
class FakeEgress extends EgressClient
{
    /**
     * @var list<array<string,mixed>>
     */
    public array $calls = [];

    public function __construct(private int $status = 200, private string $body = '{}')
    {
        parent::__construct(null, ['allow_private' => true]);
    }

    public function request(string $method, string $url, array $options = []): EgressResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new EgressResponse($this->status, [], $this->body);
    }
}
