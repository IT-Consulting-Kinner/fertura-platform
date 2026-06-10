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
        // Policy-Prüfung + (für Hostnamen) EINMALIGE Auflösung; das Ergebnis wird
        // direkt zur Pin-Zeile — kein zweites/drittes DNS pro Request.
        $pin = $this->validatedPinFor($url);

        // Die SSRF-Garantien (IP-Pinning gegen DNS-Rebinding, In-Flight-Größenlimit)
        // werden über den Curl-Adapter durchgesetzt. Fehlt ext-curl, fiele Cake still
        // auf den Stream-Adapter zurück, der die `curl`-Optionen ignoriert — der Schutz
        // wäre wirkungslos. Bei aktivem SSRF-Schutz dann lieber fail-closed.
        if (empty($this->config['allow_private']) && !extension_loaded('curl')) {
            throw new EgressException(
                'Outbound-HTTP erfordert die curl-Erweiterung (SSRF-Pinning/Größenlimit) — nicht verfügbar.',
            );
        }

        $headers = array_merge(
            ['User-Agent' => (string)$this->config['user_agent']],
            (array)($options['headers'] ?? []),
        );
        // Trace fortführen (P04), falls ein Kontext gesetzt und nicht überschrieben.
        $traceparent = \App\Log\Trace::traceparent();
        if ($traceparent !== null && !isset($headers['traceparent'])) {
            $headers['traceparent'] = $traceparent;
        }
        $opts = [
            'timeout' => (int)$this->config['timeout_seconds'],
            'headers' => $headers,
            // Keine Redirects folgen: ein validiertes (öffentliches) Ziel könnte
            // sonst auf ein internes/privates Ziel umleiten und den SSRF-Schutz
            // umgehen. 3xx wird unverändert zurückgegeben (Aufrufer entscheidet).
            'redirect' => 0,
        ];
        // DNS-Rebinding-Schutz: Verbindung auf die oben **validierte** IP pinnen
        // (CURLOPT_RESOLVE) — curl verbindet nur auf eine der geprüften Adressen
        // und löst nicht selbst auf (kein Dual-Stack-/Rebinding-Ausweichen).
        if ($pin !== null) {
            $opts['curl'][CURLOPT_RESOLVE] = [$pin];
        }
        // Antwortgrößen-Limit BEREITS WÄHREND des Transfers durchsetzen (statt erst
        // nach dem vollständigen Puffern): ein bösartiger Server kann `Content-Length`
        // weglassen und beliebig viel streamen -> Speicher-DoS. Der Curl-Fortschritts-
        // callback bricht ab, sobald mehr als das Limit geladen wurde (gebundener
        // Speicher ≈ Limit + ein Puffer-Chunk). Greift mit dem Curl-Adapter; der
        // Stream-Adapter fällt auf die nachgelagerte Begrenzung unten zurück.
        $max = (int)$this->config['max_response_bytes'];
        $overLimit = false;
        if ($max > 0) {
            $opts['curl'][CURLOPT_NOPROGRESS] = false;
            $opts['curl'][CURLOPT_XFERINFOFUNCTION] =
                static function ($ch, $dlTotal, $dlNow) use ($max, &$overLimit): int {
                    if ($dlNow > $max) {
                        $overLimit = true;

                        return 1; // != 0 -> Transfer abbrechen
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
     * Bestimmt für einen (Hostnamen-)Aufruf die auf die **validierte** IP
     * gepinnte CURLOPT_RESOLVE-Zeile `host:port:ip`. Gibt null zurück, wenn
     * Pinning nicht nötig/erwünscht ist (IP-Literal, `allow_private`, Allowlist).
     * Wirft, wenn der Host (erneut) auf ein privates/reserviertes Ziel auflöst.
     */
    public function pinTarget(string $url): ?string
    {
        $parts = parse_url($url);
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if ($host === '' || !empty($this->config['allow_private'])) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null; // IP-Literal: kein DNS, kein Rebinding
        }
        if (in_array(strtolower($host), array_map('strtolower', (array)$this->config['allowlist']), true)) {
            return null; // Betreiber hat den Host bewusst freigegeben
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
     * Kombiniert Policy-Prüfung (Schema/Host/Allowlist/`allow_private`/IP-Literal)
     * und — für Hostnamen — die **einmalige** Auflösung+Validierung zur Pin-Zeile.
     * So löst ein Request den Host genau einmal auf (statt in `assertUrlAllowed`
     * UND `pinTarget` getrennt). Gibt die CURLOPT_RESOLVE-Zeile zurück oder null,
     * wenn nicht gepinnt wird (IP-Literal/Allowlist/`allow_private`).
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

            return null; // IP-Literal: validiert, aber kein DNS/Pin
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
     * Baut die CURLOPT_RESOLVE-Zeile `host:port:ip1,[ipv6],…` aus geprüften IPs.
     *
     * @param array<string,mixed> $parts parse_url-Ergebnis
     * @param list<string> $ips
     */
    private function pinLine(string $host, array $parts, array $ips): string
    {
        $port = (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'http' ? 80 : 443));
        // IPv6 in CURLOPT_RESOLVE in eckigen Klammern.
        $formatted = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $ips,
        );

        return $host . ':' . $port . ':' . implode(',', $formatted);
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
        // Beide Familien auflösen: IPv4 (A) UND IPv6 (AAAA). Sonst bliebe ein
        // privater AAAA-Record bei einem Dual-Stack-Host ungeprüft (curl könnte
        // ihn ansteuern) — die reine IPv4-Auflösung war hier die SSRF-Lücke.
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
