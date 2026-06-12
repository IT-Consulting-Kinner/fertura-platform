<?php
declare(strict_types=1);

namespace App\Service\Http;

use App\Log\Trace;
use App\Service\Settings\SettingsManager;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Hardened outbound-HTTP primitive of the core (program tier-1, P01).
 *
 * Shared egress for **all** outward-facing calls from the core and the modules
 * (webhooks, OIDC token/JWKS, AI gateway, marketplace …). In particular it
 * guards against **SSRF**: without explicit permission, targets in private/
 * reserved networks (loopback, RFC1918, link-local incl. cloud metadata
 * 169.254.169.254) are **blocked**. Additionally: only `http`/`https`, a
 * timeout, a response-size limit, and a fixed user agent.
 *
 * Configuration precedence: explicitly passed `$config` > DB settings
 * (`core.http.egress.*`) > code defaults. This lets the client be tested
 * deterministically (without a DB) and steered via settings in production.
 */
class EgressClient
{
    private Client $client;

    /**
     * @var array<string, mixed>
     */
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
     * Executes an HTTP request (after the policy check).
     *
     * @param array{headers?:array<string,string>, data?:mixed, type?:string} $options
     */
    public function request(string $method, string $url, array $options = []): EgressResponse
    {
        if (empty($this->config['enabled'])) {
            throw new EgressException('HTTP-Egress ist deaktiviert (core.http.egress.enabled).');
        }
        // Policy check + (for hostnames) a SINGLE resolution; the result becomes
        // the pin line directly — no second/third DNS lookup per request.
        $pin = $this->validatedPinFor($url);

        // The SSRF guarantees (IP pinning against DNS rebinding, in-flight size
        // limit) are enforced via the curl adapter. If ext-curl is missing, Cake
        // would silently fall back to the stream adapter, which ignores the `curl`
        // options — the protection would be ineffective. With SSRF protection
        // active, fail closed instead.
        if (empty($this->config['allow_private']) && !extension_loaded('curl')) {
            throw new EgressException(
                'Outbound-HTTP erfordert die curl-Erweiterung (SSRF-Pinning/Größenlimit) — nicht verfügbar.',
            );
        }

        $headers = array_merge(
            ['User-Agent' => (string)$this->config['user_agent']],
            (array)($options['headers'] ?? []),
        );
        // Continue the trace (P04) if a context is set and not overridden.
        $traceparent = Trace::traceparent();
        if ($traceparent !== null && !isset($headers['traceparent'])) {
            $headers['traceparent'] = $traceparent;
        }
        $opts = [
            'timeout' => (int)$this->config['timeout_seconds'],
            'headers' => $headers,
            // Don't follow redirects: a validated (public) target could
            // otherwise redirect to an internal/private target and bypass the
            // SSRF protection. 3xx is returned unchanged (the caller decides).
            'redirect' => 0,
        ];
        // DNS-rebinding protection: pin the connection to the **validated** IP
        // from above (CURLOPT_RESOLVE) — curl only connects to one of the
        // checked addresses and does not resolve itself (no dual-stack/rebinding
        // escape).
        if ($pin !== null) {
            $opts['curl'][CURLOPT_RESOLVE] = [$pin];
        }
        // Enforce the response-size limit ALREADY DURING the transfer (instead of
        // only after fully buffering): a malicious server can omit `Content-Length`
        // and stream arbitrarily much -> memory DoS. The curl progress callback
        // aborts as soon as more than the limit has been downloaded (bounded
        // memory ≈ limit + one buffer chunk). Effective with the curl adapter; the
        // stream adapter falls back to the downstream cap below.
        $max = (int)$this->config['max_response_bytes'];
        $overLimit = false;
        if ($max > 0) {
            $opts['curl'][CURLOPT_NOPROGRESS] = false;
            $opts['curl'][CURLOPT_XFERINFOFUNCTION] =
                static function ($ch, $dlTotal, $dlNow) use ($max, &$overLimit): int {
                    if ($dlNow > $max) {
                        $overLimit = true;

                        return 1; // != 0 -> abort transfer
                    }

                    return 0;
                };
        }
        if (isset($options['type'])) {
            $opts['type'] = $options['type'];
        }
        $data = $options['data'] ?? [];

        try {
            $resp = $this->sendRequest($method, $url, $data, $opts);
        } catch (EgressException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($overLimit) {
                throw new EgressException('Antwort zu groß (über ' . $max . ' Bytes — Transfer abgebrochen).');
            }
            throw new EgressException('HTTP-Egress fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

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
     * POST with a JSON body (`Content-Type: application/json`).
     *
     * @param array<string, mixed> $payload
     * @param array{headers?:array<string,string>} $options
     */
    public function postJson(string $url, array $payload, array $options = []): EgressResponse
    {
        return $this->request('POST', $url, ['data' => $payload, 'type' => 'json'] + $options);
    }

    /**
     * Checks whether a target is allowed by the egress policy (without throwing).
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
     * For a (hostname) call, determines the CURLOPT_RESOLVE line `host:port:ip`
     * pinned to the **validated** IP. Returns null when pinning is not
     * needed/wanted (IP literal, `allow_private`, allowlist). Throws if the host
     * (re-)resolves to a private/reserved target.
     */
    public function pinTarget(string $url): ?string
    {
        $parts = parse_url($url);
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if ($host === '' || !empty($this->config['allow_private'])) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null; // IP literal: no DNS, no rebinding
        }
        if (in_array(strtolower($host), array_map('strtolower', (array)$this->config['allowlist']), true)) {
            return null; // operator has deliberately allowlisted the host
        }
        $ips = $this->resolveHostIps($host);
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new EgressException("Ziel-IP $ip (Host $host) ist privat/reserviert — blockiert (SSRF-Schutz).");
            }
        }

        return $this->pinLine($host, $parts, $ips);
    }

    /**
     * Combines the policy check (scheme/host/allowlist/`allow_private`/IP literal)
     * and — for hostnames — the **single** resolution+validation into the pin
     * line. This way a request resolves the host exactly once (instead of
     * separately in `assertUrlAllowed` AND `pinTarget`). Returns the
     * CURLOPT_RESOLVE line, or null if no pinning is applied
     * (IP literal/allowlist/`allow_private`).
     */
    private function validatedPinFor(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new EgressException("Nur http/https erlaubt (Schema: '$scheme').");
        }
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if ($host === '') {
            throw new EgressException('URL ohne Host.');
        }
        if (!empty($this->config['allow_private'])) {
            return null;
        }
        if (in_array(strtolower($host), array_map('strtolower', (array)$this->config['allowlist']), true)) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isPublicIp($host)) {
                throw new EgressException("Ziel-IP $host ist privat/reserviert — blockiert (SSRF-Schutz).");
            }

            return null; // IP literal: validated, but no DNS/pin
        }
        $ips = $this->resolveHostIps($host);
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new EgressException(
                    "Ziel-IP $ip (Host $host) ist privat/reserviert — blockiert (SSRF-Schutz). "
                    . 'Per core.http.egress.allowlist freigeben, falls beabsichtigt.',
                );
            }
        }

        return $this->pinLine($host, $parts, $ips);
    }

    /**
     * Builds the CURLOPT_RESOLVE line `host:port:ip1,[ipv6],…` from the checked IPs.
     *
     * @param array<string,mixed> $parts parse_url result
     * @param list<string> $ips
     */
    private function pinLine(string $host, array $parts, array $ips): string
    {
        $port = (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
        // IPv6 in CURLOPT_RESOLVE goes in square brackets.
        $formatted = array_map(
            static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $ips,
        );

        return $host . ':' . $port . ':' . implode(',', $formatted);
    }

    /**
     * Enforces the egress policy: only http/https, no private/reserved target
     * (unless permitted via allowlist/`allow_private`).
     */
    public function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new EgressException("Nur http/https erlaubt (Schema: '$scheme').");
        }
        $host = trim((string)($parts['host'] ?? ''), '[]'); // strip IPv6 brackets
        if ($host === '') {
            throw new EgressException('URL ohne Host.');
        }
        if (!empty($this->config['allow_private'])) {
            return; // deliberate operator override (e.g. internal integration/dev)
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
     * Sends the request via the HTTP client. A dedicated method so tests can
     * override it without a network.
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
     * Resolves a host into IP addresses (IP literals unchanged). A dedicated
     * method for test stubs.
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        // Resolve both families: IPv4 (A) AND IPv6 (AAAA). Otherwise a private
        // AAAA record on a dual-stack host would go unchecked (curl could target
        // it) — IPv4-only resolution was the SSRF gap here.
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string)$rec['ipv6'];
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new EgressException("Host nicht auflösbar: $host");
        }

        return $ips;
    }

    /** An IP is "public" when it is NOT within private/reserved ranges. */
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
            return []; // settings unavailable (e.g. unit test) -> defaults/override
        }
    }
}
