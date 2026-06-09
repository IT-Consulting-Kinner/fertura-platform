<?php
declare(strict_types=1);

namespace App\Service\Http;

use App\Service\Settings\SettingsManager;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Gehärtetes Outbound-HTTP-Primitiv des Core (Programm Tier-1, P01).
 *
 * Gemeinsamer Ausgang für **alle** nach außen gerichteten Aufrufe des Core und
 * der Module (Webhooks, OIDC-Token/JWKS, AI-Gateway, Marketplace …). Schützt
 * insbesondere vor **SSRF**: ohne explizite Erlaubnis werden Ziele in privaten/
 * reservierten Netzen (Loopback, RFC1918, Link-Local inkl. Cloud-Metadaten
 * 169.254.169.254) **blockiert**. Zusätzlich: nur `http`/`https`, Timeout,
 * Antwortgrößen-Limit, fester User-Agent.
 *
 * Reihenfolge der Konfiguration: explizit übergebenes `$config` > DB-Settings
 * (`core.http.egress.*`) > Code-Defaults. So lässt sich der Client deterministisch
 * (ohne DB) testen und im Betrieb über die Settings steuern.
 */
class EgressClient
{
    private Client $client;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(?Client $client = null, array $config = [])
    {
        $this->client = $client ?? new Client();
        $this->config = array_merge($this->defaults(), $this->loadSettings(), $config);
    }

    /**
     * Führt eine HTTP-Anfrage aus (nach Policy-Prüfung).
     *
     * @param array{headers?:array<string,string>, data?:mixed, type?:string} $options
     */
    public function request(string $method, string $url, array $options = []): EgressResponse
    {
        if (empty($this->config['enabled'])) {
            throw new EgressException('HTTP-Egress ist deaktiviert (core.http.egress.enabled).');
        }
        $this->assertUrlAllowed($url);

        $opts = [
            'timeout' => (int)$this->config['timeout_seconds'],
            'headers' => array_merge(
                ['User-Agent' => (string)$this->config['user_agent']],
                (array)($options['headers'] ?? []),
            ),
        ];
        if (isset($options['type'])) {
            $opts['type'] = $options['type'];
        }
        $data = $options['data'] ?? [];

        try {
            $resp = $this->sendRequest($method, $url, $data, $opts);
        } catch (EgressException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EgressException('HTTP-Egress fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        $max = (int)$this->config['max_response_bytes'];
        if ($max > 0 && (int)$resp->getHeaderLine('Content-Length') > $max) {
            throw new EgressException('Antwort zu groß (Content-Length über ' . $max . ' Bytes).');
        }
        $body = $resp->getStringBody();
        if ($max > 0 && strlen($body) > $max) {
            $body = substr($body, 0, $max);
        }

        Log::debug('egress', [
            'component' => 'egress',
            'method' => strtoupper($method),
            'host' => (string)parse_url($url, PHP_URL_HOST),
            'status' => $resp->getStatusCode(),
        ]);

        return new EgressResponse($resp->getStatusCode(), $resp->getHeaders(), $body);
    }

    /**
     * @param array<string, scalar> $query
     * @param array{headers?:array<string,string>} $options
     */
    public function get(string $url, array $query = [], array $options = []): EgressResponse
    {
        return $this->request('GET', $url, ['data' => $query] + $options);
    }

    /**
     * POST mit JSON-Body (`Content-Type: application/json`).
     *
     * @param array<string, mixed> $payload
     * @param array{headers?:array<string,string>} $options
     */
    public function postJson(string $url, array $payload, array $options = []): EgressResponse
    {
        return $this->request('POST', $url, ['data' => $payload, 'type' => 'json'] + $options);
    }

    /**
     * Prüft, ob ein Ziel nach der Egress-Policy erlaubt ist (ohne zu werfen).
     */
    public function isUrlAllowed(string $url): bool
    {
        try {
            $this->assertUrlAllowed($url);

            return true;
        } catch (EgressException) {
            return false;
        }
    }

    /**
     * Erzwingt die Egress-Policy: nur http/https, kein privates/reserviertes Ziel
     * (außer per Allowlist/`allow_private` freigegeben).
     */
    public function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new EgressException("Nur http/https erlaubt (Schema: '$scheme').");
        }
        $host = trim((string)($parts['host'] ?? ''), '[]'); // IPv6-Klammern entfernen
        if ($host === '') {
            throw new EgressException('URL ohne Host.');
        }
        if (!empty($this->config['allow_private'])) {
            return; // bewusster Betreiber-Override (z. B. interne Integration/Dev)
        }
        $allowlist = array_map('strtolower', (array)$this->config['allowlist']);
        if (in_array(strtolower($host), $allowlist, true)) {
            return;
        }
        foreach ($this->resolveHostIps($host) as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new EgressException(
                    "Ziel-IP $ip (Host $host) ist privat/reserviert — blockiert (SSRF-Schutz). "
                    . 'Per core.http.egress.allowlist freigeben, falls beabsichtigt.',
                );
            }
        }
    }

    /**
     * Sendet die Anfrage über den HTTP-Client. Eigene Methode, damit Tests sie
     * ohne Netzwerk überschreiben können.
     *
     * @param array<string, mixed> $opts
     */
    protected function sendRequest(string $method, string $url, mixed $data, array $opts): Response
    {
        return match (strtoupper($method)) {
            'GET' => $this->client->get($url, (array)$data, $opts),
            'POST' => $this->client->post($url, $data, $opts),
            'PUT' => $this->client->put($url, $data, $opts),
            'PATCH' => $this->client->patch($url, $data, $opts),
            'DELETE' => $this->client->delete($url, $data, $opts),
            default => throw new EgressException("HTTP-Methode nicht unterstützt: $method"),
        };
    }

    /**
     * Löst einen Host in IPv4-Adressen auf (IP-Literale unverändert). Eigene
     * Methode für Test-Stubs.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = @gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new EgressException("Host nicht auflösbar: $host");
        }

        return array_values($ips);
    }

    /** Eine IP ist „öffentlich", wenn sie NICHT in privaten/reservierten Bereichen liegt. */
    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'enabled' => true,
            'timeout_seconds' => 10,
            'max_response_bytes' => 5_000_000,
            'allow_private' => false,
            'allowlist' => [],
            'user_agent' => 'Fertura/1.0 (+egress)',
        ];
    }

    /** @return array<string, mixed> */
    private function loadSettings(): array
    {
        try {
            $sm = new SettingsManager();

            return [
                'enabled' => (bool)$sm->get('core', 'http.egress.enabled', true),
                'timeout_seconds' => (int)$sm->get('core', 'http.egress.timeout_seconds', 10),
                'max_response_bytes' => (int)$sm->get('core', 'http.egress.max_response_bytes', 5_000_000),
                'allow_private' => (bool)$sm->get('core', 'http.egress.allow_private', false),
                'allowlist' => (array)$sm->get('core', 'http.egress.allowlist', []),
                'user_agent' => (string)$sm->get('core', 'http.egress.user_agent', 'Fertura/1.0 (+egress)'),
            ];
        } catch (Throwable) {
            return []; // Settings nicht verfügbar (z. B. Unit-Test) -> Defaults/Override
        }
    }
}
