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
- [ ] **P04 — Metrics + Tracing.** Prometheus-`/metrics` (auth/Token-geschützt),
  OpenTelemetry-kompatible Trace-/Span-IDs (Wiederverwendung `correlation_id`),
  Counters/Histogramme für HTTP/Worker/Outbox/RPC. *Instrumentiert alles Spätere.*

### Phase B — Integrations-Oberfläche (Tier-1-Kern)
- [ ] **P05 — Outbound-Webhooks.** Auf Outbox + P01: Subscriptions je
  Event/Contract, **HMAC-signierte** Zustellung, Retry/Backoff, Dead-Letter,
  Admin-UI, Zustell-Audit. *Größter Integrations-Hebel.*
- [ ] **P06 — OIDC/OAuth2-SSO.** First-Party-Provider am `core.auth.provider`-Slot
  (Authorization-Code + PKCE), JWT/JWKS-Validierung, Account-Linking,
  Break-Glass-Fallback. SAML als spätere Folgestufe vermerkt.
- [ ] **P07 — Plattform-API-Reife.** OpenAPI-Generierung, **Rate-Limiting** (über
  P02), Modul-Endpunkt-Registrierung (Contract `core.api.route`), API-Versionierung.

### Phase C — Nutzer-Interaktion
- [ ] **P08 — Echtzeit (SSE).** Server-Sent-Events über `LISTEN/NOTIFY`,
  token-/session-authentifiziert, je-Nutzer-Kanal. *Basis für In-App-Notifs.*
- [ ] **P09 — Notification-Framework.** Bus mit Kanälen (Mail / In-App via P08 /
  Webhook via P05), Nutzer-**Präferenzen**, Templating, Digest/Bündelung,
  Collector-Contract für Modul-Benachrichtigungen.

### Phase D — Wissen & Automatisierung
- [ ] **P10 — Volltext-Suche.** Postgres `tsvector` als opt-in Core-Capability
  (`core.search.indexable`), Index-Pflege über Events, Ranking, Such-API.
- [ ] **P11 — AI/LLM-Primitive.** `pgvector`-Embedding-Store + provider-agnostisches
  **LLM-Gateway** (über P01) mit Key-/Kosten-/Rate-Management + Audit; semantische
  Suche speist P10. Capability `core.ai.complete` / `core.ai.embed`.
- [ ] **P12 — Workflow-/Automations-Engine.** Deklarative Event-Condition-Action-
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
| P03 Objekt-Storage | E78 | (folgt) | fertig — Flysystem lokal+S3 |
