# Programm „Wettbewerbsfähigkeit Core" — Tier 1–3 (vollständige Reihenfolge)

Umsetzung aller im Plattform-Review identifizierten Tier-1/2/3-Funktionen, in
**abhängigkeits-sortierter** Reihenfolge. Jeder Punkt = eigenes Core-Primitiv mit
Tests, Doku (Anforderungsdokument-Kapitel + Changelog) und eigenem Commit/Push
(fortlaufende Entscheidungs-Log-Nummern E76 ff.).

## Querschnitts-Entscheidungen (autonom getroffen)

- **D-A — Single-Org-Modell bleibt.** Echte Multi-Tenancy (First-Class-Mandant)
  war **nicht** Teil von Tier 1–3 und bleibt eine separate strategische
  Weichenstellung. Alle Primitive hier respektieren das bestehende
  RLS-Owner-Scoping (Nutzer/Gruppe).
- **D-B — Auf dem Stack aufbauen, Schwer-Deps spät & gezielt.** Wo möglich ohne
  neue Abhängigkeit (z. B. `Cake\Http\Client`, Postgres `tsvector`, `Cake\Cache`,
  SSE über `LISTEN/NOTIFY`). Schwere Deps (Flysystem/S3, JWT/JWK, PhpSpreadsheet,
  pgvector) werden erst beim jeweiligen Feature hinzugefügt und dokumentiert.
- **D-C — Einheitliches Muster je Feature.** Core-Primitiv + (wo sinnvoll)
  Contract/Capability, Settings im `SettingsCatalog`, Audit/Health-Anbindung,
  Tests grün, Doku nachgezogen, eigener Commit + Push, Worker-Neustart.
- **D-D — Sicherheit zuerst.** Alle nach außen gerichteten Primitive (Egress,
  Webhooks, OIDC, AI-Gateway) mit SSRF-/Allowlist-/Secret-Redaction-Schutz.

## Reihenfolge

### Phase A — Querschnitts-Infrastruktur (Fundament)
- [x] **P01 — HTTP-Egress-Primitiv** (E76). Gehärteter Outbound-HTTP-Client auf
  `Cake\Http\Client`: Timeouts, Retry/Backoff (opt-in), **SSRF-Schutz**
  (Private/Loopback/Link-Local blockiert, Allowlist), Antwortgrößen-Limit,
  Secret-Redaction in Logs, Audit/Metrics-Hooks. *Fundament für P05/P06/P11.*
- [x] **P02 — Cache-Abstraktion** (E77). `Cake\Cache`-Konfiguration (File-Default,
  Redis/APCu optional via Env), Helfer für get/remember/invalidate; Settings-Cache
  mit TTL + Invalidierung bei Änderung. *Fundament für P07-Rate-Limit, P10.*
- [x] **P03 — Objekt-Storage-Abstraktion** (E78). Flysystem + S3-kompatibler Adapter
  (lokal als Default), einheitliche `StorageManager`-API. *Fundament für P13/P14.*
- [x] **P04 — Metrics + Tracing** (E79). Prometheus-`/metrics` (auth/Token-geschützt),
  OpenTelemetry-kompatible Trace-/Span-IDs (Wiederverwendung `correlation_id`),
  Counters/Histogramme für HTTP/Worker/Outbox/RPC. *Instrumentiert alles Spätere.*

### Phase B — Integrations-Oberfläche (Tier-1-Kern)
- [x] **P05 — Outbound-Webhooks** (E80). _Admin-GUI bewusst auf später verschoben
  (i18n-Aufwand); Verwaltung über CLI `webhook` + künftige API (P07)._ Auf Outbox + P01: Subscriptions je
  Event/Contract, **HMAC-signierte** Zustellung, Retry/Backoff, Dead-Letter,
  Admin-UI, Zustell-Audit. *Größter Integrations-Hebel.*
- [x] **P06 — OIDC/OAuth2-SSO + SAML** (E81). _web-token (OIDC, PKCE+JWKS) +
  onelogin/php-saml; CLI `sso`; Admin-GUI später._ First-Party-Provider am `core.auth.provider`-Slot
  (Authorization-Code + PKCE), JWT/JWKS-Validierung, Account-Linking,
  Break-Glass-Fallback. SAML als spätere Folgestufe vermerkt.
- [x] **P07 — Plattform-API-Reife** (E82). Rate-Limiting (Cache/P02), OpenAPI-3.1-
  Generierung, Modul-Endpunkt-Registrierung via `core.api.route`. OpenAPI-Generierung, **Rate-Limiting** (über
  P02), Modul-Endpunkt-Registrierung (Contract `core.api.route`), API-Versionierung.

### Phase C — Nutzer-Interaktion
- [x] **P08 — Echtzeit (SSE)** (E83). Server-Sent-Events über `LISTEN/NOTIFY`,
  token-/session-authentifiziert, je-Nutzer-Kanal. *Basis für In-App-Notifs.*
- [x] **P09 — Notification-Framework** (E84). _In-App (SSE) + E-Mail + Modul-Kanäle
  (`core.collector.notification_channel`) + Webhook-Event; API + Präferenzen._ Bus mit Kanälen (Mail / In-App via P08 /
  Webhook via P05), Nutzer-**Präferenzen**, Templating, Digest/Bündelung,
  Collector-Contract für Modul-Benachrichtigungen.

### Phase D — Wissen & Automatisierung
- [x] **P10 — Volltext-Suche** (E85). Postgres `tsvector`, Owner-Scoping, API,
  Collector `core.collector.search`. Postgres `tsvector` als opt-in Core-Capability
  (`core.search.indexable`), Index-Pflege über Events, Ranking, Such-API.
- [x] **P11 — AI/LLM-Primitive** (E86). `pgvector`-Embedding-Store + provider-
  agnostisches **LLM-Gateway** (über P01) für OpenAI/Anthropic/xAI/Google;
  semantische Suche; Capabilities `core.ai.complete`/`core.ai.embed`. DB-Image →
  `pgvector/pgvector:pg17`.
- [x] **P12 — Workflow-/Automations-Engine** (E87). Deklarative Event-Condition-Action-
  Regeln + State-Machines auf Events (P-Outbox) mit Aktionen (Notify/Webhook/
  Service-Call), Admin-UI, Audit.

### Phase E — Auswertung & Betrieb
- [ ] **P13 — Reporting/Export-Primitive.** Generische CSV/XLSX/PDF-Erzeugung
  (PhpSpreadsheet + PDF), gestreamt/abgelegt über P03, mit Audit.
- [ ] **P14 — Off-Site-Backup + PITR.** WAL-Archivierung + Backup-Ziel auf
  Objekt-Storage (P03), Point-in-Time-Recovery-Runbook.
- [ ] **P15 — Zero-Downtime-Updates.** Expand/Contract-Migrationsmuster,
  blue-green/rolling-fähiger Update-Fluss statt 503-Wartungsmodus.

### Phase F — Ökosystem
- [ ] **P16 — Modul-SDK / Scaffolding / Dev-Mode / Manifest-Linter.** CLI zum
  Modul-Gerüst, lokaler Dev-Modus, Manifest-/Contract-Linter, Test-Harness,
  publizierte Interface-Docs — spiegelt die nun reichere Oberfläche.

## Fortschritt

Abgeschlossene Punkte werden oben abgehakt und hier mit E-Nummer + Commit notiert.

| Punkt | E-Nr. | Commit | Stand |
|------|-------|--------|-------|
| —    | —     | —      | Programm angelegt |
| P01 HTTP-Egress-Primitiv | E76 | a8ec0bd | fertig — 98 Tests grün |
| P02 Cache-Abstraktion | E77 | 4ac3006 | fertig — 102 Tests grün |
| P03 Objekt-Storage | E78 | e347265 | fertig — Flysystem lokal+S3 |
| P04 Metrics + Tracing | E79 | (folgt) | fertig — 115 Tests grün, /metrics live |

| P05 Outbound-Webhooks | E80 | 2eb291d | fertig — 119 Tests grün |
| P06 OIDC + SAML SSO | E81 | fc8cf76 | fertig — 130 Tests grün, Login-Buttons live |
| P07 API-Reife | E82 | (folgt) | fertig — 136 Tests grün, /openapi.json + Rate-Limit live |

| P08 Echtzeit (SSE) | E83 | 38cb836 | fertig — 138 Tests grün, Stream live |
| P09 Notification-Framework | E84 | (folgt) | fertig — 142 Tests grün, API live |

| P10 Volltext-Suche | E85 | 228d025 | fertig — 145 Tests grün, /search live |
| P11 AI/LLM (pgvector) | E86 | 8f6b199 | fertig — 153 Tests grün, 4 Provider |
| P12 Workflow-Engine | E87 | (folgt) | fertig — 160 Tests grün |

**Phasen A–D abgeschlossen** (P01–P12). Nächster Halt: Zwischenstand, dann
Phase E (P13 Reporting/Export, P14 Off-Site-Backup/PITR, P15 Zero-Downtime).

**Phasen A–C abgeschlossen** (P01–P09). Phase D läuft: P10 fertig, als Nächstes
P11 AI/LLM (Provider: OpenAI/Anthropic/xAI/Google), P12 Workflow-Engine.
