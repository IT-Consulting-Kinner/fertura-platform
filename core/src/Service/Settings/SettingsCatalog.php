<?php
declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Catalog of the known core settings: safe default values (which apply even
 * without a DB entry, ch. 27.16.3 / Decision 162), type and value-range
 * validation, and the secret flag (encrypted storage, Decision 159).
 *
 * Definition per key: type (int|bool|string|json), default, optionally min/max
 * (for int) and secret (bool).
 */
class SettingsCatalog
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private const DEFINITIONS = [
        'core' => [
            // Password policy (a code default in Step 2, now DB-configurable).
            'password.min_length' => ['type' => 'int', 'default' => 12, 'min' => 6, 'max' => 128],
            // Login protection (Decision 162).
            'login_throttle.max_attempts' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 1000],
            'login_throttle.window_minutes' => ['type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440],
            // Per-IP cap on failed attempts (across all usernames) within the same
            // window — defends against password spraying / pre-auth CPU load.
            'login_throttle.ip_max_attempts' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 100000],
            // Session timeout (wiring into the session follows with the GUI, Step 10).
            'session.timeout_minutes' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 43200],
            // Example of an encrypted secret.
            'smtp.password' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Internationalization (i18n, E37): default language + offered languages.
            'locale.default' => ['type' => 'string', 'default' => 'en_US'],
            'locale.enabled' => ['type' => 'json', 'default' => ['en_US', 'de_DE']],
            // System/identity mail (invitation, password reset).
            'mail.enabled' => ['type' => 'bool', 'default' => true],
            'mail.from_address' => ['type' => 'string', 'default' => 'no-reply@fertura.local'],
            'mail.from_name' => ['type' => 'string', 'default' => 'Fertura'],
            // Marketplace / signature / maintenance (Step 8).
            'require_module_signature' => ['type' => 'bool', 'default' => true],
            'crl_max_age_days' => ['type' => 'int', 'default' => 7, 'min' => 0, 'max' => 365],
            // Maximum age of the online license confirmation (ch. 28.7.3.1).
            'license.online_max_age_days' => ['type' => 'int', 'default' => 7, 'min' => 1, 'max' => 365],
            'marketplace.base_url' => ['type' => 'string', 'default' => null],
            'maintenance_mode' => ['type' => 'bool', 'default' => false],
            // Observability (Step 12, ch. 20.2).
            'storage.path' => ['type' => 'string', 'default' => null],
            // Object storage driver (P03): 'local' (default) or 's3'
            // (S3 credentials supplied out-of-band via STORAGE_S3_* env).
            'storage.driver' => ['type' => 'string', 'default' => 'local'],
            // Off-site backup (P14): additionally upload backups to object storage.
            'backup.offsite.enabled' => ['type' => 'bool', 'default' => false],
            'health.worker_max_age_seconds' => ['type' => 'int', 'default' => 120, 'min' => 10, 'max' => 86400],
            'health_token' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Observability (#12): webhook URL for health alerts (status changes
            // up<->degraded/down), signed via HMAC with health.alert_secret. The
            // OTLP metric export runs separately via OTEL_EXPORTER_OTLP_ENDPOINT (env).
            'health.alert_url' => ['type' => 'string', 'default' => null],
            'health.alert_secret' => ['type' => 'string', 'default' => null, 'secret' => true],

            // Security response headers (SecurityHeadersMiddleware): can be turned
            // off if an upstream proxy sets them; CSP is replaceable (empty =
            // safe default policy); HSTS duration (only sent over TLS, 0 = off).
            'security.headers.enabled' => ['type' => 'bool', 'default' => true],
            'security.csp' => ['type' => 'string', 'default' => null],
            'security.hsts_max_age' => ['type' => 'int', 'default' => 31536000, 'min' => 0, 'max' => 63072000],
            // MFA requirement for LOCAL logins: users without TOTP set up are forced
            // to set it up after login. SSO logins are unaffected (MFA enforcement
            // lies with the IdP).
            'security.mfa.required' => ['type' => 'bool', 'default' => false],
            // Session anomaly detection (SessionGuardMiddleware): UA binding (cookie
            // theft in a different browser -> session ends), strict IP-change mode
            // (off by default: mobile networks/NAT change IPs legitimately) and an
            // in-app notice on login from a new device.
            'security.session.bind_ua' => ['type' => 'bool', 'default' => true],
            'security.session.ip_strict' => ['type' => 'bool', 'default' => false],
            'security.session.notify_new_device' => ['type' => 'bool', 'default' => true],

            // Data backup (ch. 20.1.2). Path as a container/Linux or Windows path;
            // empty = default volume. The scheduler runs in the worker.
            'backup.path' => ['type' => 'string', 'default' => null],
            'backup.schedule.enabled' => ['type' => 'bool', 'default' => false],
            'backup.schedule.interval_hours' => ['type' => 'int', 'default' => 24, 'min' => 1, 'max' => 8760],
            'backup.retention' => ['type' => 'int', 'default' => 14, 'min' => 1, 'max' => 3650],
            // Password that encrypts the archive contents (AES-256). Secret: never
            // shown in plaintext. Empty = unencrypted (warning).
            'backup.password' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Before finishing, additionally run a trial restore into a scratch DB
            // (guarantees recoverability). The integrity check always runs.
            'backup.verify_on_create' => ['type' => 'bool', 'default' => true],
            // Additional retention by age (days); 0 = off.
            'backup.retention_days' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 3650],
            // Minimum free space at the target before the backup (MB, pre-flight).
            'backup.min_free_mb' => ['type' => 'int', 'default' => 500, 'min' => 0, 'max' => 10485760],
            // Alert recipient for a failed backup (email). Empty = off.
            'backup.alert_email' => ['type' => 'string', 'default' => null],
            // Hardened outbound HTTP (P01): shared egress for webhooks/OIDC/AI.
            'http.egress.enabled' => ['type' => 'bool', 'default' => true],
            'http.egress.timeout_seconds' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120],
            // Max response size in bytes (0 = unlimited); default 5 MiB.
            'http.egress.max_response_bytes' => ['type' => 'int', 'default' => 5000000, 'min' => 0, 'max' => 104857600],
            // Disable SSRF protection (allow private/reserved targets) — set only deliberately.
            'http.egress.allow_private' => ['type' => 'bool', 'default' => false],
            // Hostnames/IP literals allowed despite private resolution (internal integrations).
            'http.egress.allowlist' => ['type' => 'json', 'default' => []],
            'http.egress.user_agent' => ['type' => 'string', 'default' => 'Fertura/1.0 (+egress)'],
            // API rate limiting (P07): per token (or IP) and minute.
            'api.rate_limit.enabled' => ['type' => 'bool', 'default' => true],
            'api.rate_limit.per_minute' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 100000],
            // Max concurrent SSE streams per user (P08; guards against FPM/DB exhaustion).
            'sse.max_streams_per_user' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 100],
            // Multi-tenancy: reject a logged-in user on the domain of a FOREIGN
            // tenant (cross-tenant host policy). Single-org is unaffected.
            'tenancy.enforce_host_match' => ['type' => 'bool', 'default' => true],
            // Driver of the generic job-queue transport (#10): `db` (default,
            // Postgres) or `redis` (Redis Streams; QUEUE_REDIS_URL). The event
            // outbox is unaffected by this (always DB).
            'queue.transport' => ['type' => 'string', 'default' => 'db'],
            // Per-tenant cap on outbox events per worker batch (fairness/quota in
            // the multi-tenant pool; default practically unlimited — fairness already
            // comes from round-robin claiming). Settable per tenant via settings overlay.
            'outbox.max_per_tenant_per_batch' => ['type' => 'int', 'default' => 10000, 'min' => 1, 'max' => 100000],
            // Full-text language configuration (Postgres regconfig) for stemming/stopwords.
            // Set by the migration from `SEARCH_TEXT_CONFIG` (default `german`) and
            // must match the tsvector column; the index additionally carries `simple`
            // (exact/language-independent matches). `simple` = no stemming.
            'search.text_config' => ['type' => 'string', 'default' => 'simple'],
            // AI/LLM gateway (P11): provider openai|anthropic|xai|google. Keys supplied
            // out-of-band via *_API_KEY env. Empty = AI disabled.
            'ai.chat.provider' => ['type' => 'string', 'default' => null],
            'ai.chat.model' => ['type' => 'string', 'default' => null],
            'ai.embed.provider' => ['type' => 'string', 'default' => null],
            'ai.embed.model' => ['type' => 'string', 'default' => null],
            // When indexing a search document, automatically also create an embedding
            // (for hybrid search), provided an embedding provider is active. Off by
            // default, since each document incurs a (synchronous) LLM call;
            // recommended for bulk reindex / background indexing.
            'ai.embed.auto_index' => ['type' => 'bool', 'default' => false],
            // Optional per-provider endpoint override (e.g. Azure OpenAI, a
            // self-hosted/proxy gateway like LiteLLM). MUST be https, otherwise the
            // API key would travel over the wire in plaintext (AiGateway rejects
            // http). Still goes through the hardened egress (SSRF protection). Only
            // the `core_config` role may set it; changes are audited. Empty = default.
            'ai.openai.endpoint' => ['type' => 'string', 'default' => null],
            'ai.xai.endpoint' => ['type' => 'string', 'default' => null],
            'ai.anthropic.endpoint' => ['type' => 'string', 'default' => null],
            'ai.google.endpoint' => ['type' => 'string', 'default' => null],
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $namespace, string $key): ?array
    {
        return self::DEFINITIONS[$namespace][$key] ?? null;
    }

    /**
     * All known definitions (for the administration GUI, Step 10).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function all(): array
    {
        return self::DEFINITIONS;
    }

    public function default(string $namespace, string $key): mixed
    {
        return self::DEFINITIONS[$namespace][$key]['default'] ?? null;
    }

    public function isSecret(string $namespace, string $key): bool
    {
        return (bool)(self::DEFINITIONS[$namespace][$key]['secret'] ?? false);
    }

    public function isKnown(string $namespace, string $key): bool
    {
        return isset(self::DEFINITIONS[$namespace][$key]);
    }

    /**
     * Validates a value against the catalog definition.
     *
     * @return list<string> List of error messages (empty = valid).
     */
    public function validate(string $namespace, string $key, mixed $value): array
    {
        $def = $this->definition($namespace, $key);
        if ($def === null) {
            return ["Unbekanntes Setting: $namespace.$key"];
        }
        if ($value === null) {
            return [];
        }

        $errors = [];
        switch ($def['type']) {
            case 'int':
                if (!is_int($value)) {
                    $errors[] = "$key muss eine Ganzzahl sein.";
                    break;
                }
                if (isset($def['min']) && $value < $def['min']) {
                    $errors[] = "$key muss >= {$def['min']} sein.";
                }
                if (isset($def['max']) && $value > $def['max']) {
                    $errors[] = "$key muss <= {$def['max']} sein.";
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = "$key muss ein Boolescher Wert sein.";
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $errors[] = "$key muss eine Zeichenkette sein.";
                }
                break;
            case 'json':
                if (!is_array($value)) {
                    $errors[] = "$key muss ein strukturierter Wert (Array/Objekt) sein.";
                }
                break;
        }

        return $errors;
    }
}
