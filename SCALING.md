# Skalierung & Betrieb (Multi-Tenant / SaaS)

Mit der Mandantenfähigkeit zeigt die Plattform Richtung Multi-Tenant/SaaS. Diese
Datei beschreibt, **was der Core liefert** (Mechanismus/Emission) und **was der
Betrieb beisteuert** (Infrastruktur/Policy) — die Grenze von Thema #10.

## Was der Core liefert (Code)

| Aspekt | Stand |
|---|---|
| **Zustandslose Web-Schicht** | Requests halten keinen lokalen Zustand; RLS-Kontext via `SET LOCAL` (pooling-/replica-fest). |
| **Session-Store** | Default **`database`** (`core.sessions`) → instanzübergreifend. `SESSION_DEFAULTS=php` für Einzelinstanz. |
| **Event-Eventing (Outbox)** | Transaktionaler Outbox + `LISTEN/NOTIFY` + `FOR UPDATE SKIP LOCKED` (multi-worker). **Mandanten-fair** (Round-Robin je `tenant_id`) + Pro-Mandant-Cap (`outbox.max_per_tenant_per_batch`). |
| **Job-Queue-Transport** | Generisches Primitiv (`QueueTransportInterface`) mit Treibern **`db`** (Default) und **`redis`** (Redis Streams). Setting `queue.transport`, `QUEUE_REDIS_URL`. Getrennt vom Event-Outbox. |
| **Periodische Aufgaben** | `pg_try_advisory_lock` → kein Doppellauf bei >1 Worker. |
| **Telemetrie** | Prometheus-`/metrics`, strukturierte JSON-Logs mit `correlation_id`/`request_id`, W3C-`traceparent` (durch den Egress weitergereicht), Health mit Subsystemen + Worker-Heartbeat. |

## Mandanten-Datenmodelle

- **Pool (Default):** shared schema, `tenant_id`-Scoping (RLS für Module, anwendungs-
  seitig für Such-/Embedding-Index). Dichteste, einfachste Variante — trägt die
  meisten SaaS-Größen.
- **DB-pro-Mandant (Option):** `tenants.db_isolated=true` → eigene Datenbank je
  Mandant (stärkste Isolation). DSN **out-of-band** über `TENANT_DB_<KEY>`,
  aufgelöst vom `TenantConnectionResolver` (fail-closed ohne DSN). Provisionierung:
  `bin/cake tenant_db_provision <key>` (migriert die dedizierte DB).
  - **Fundament-Hinweis:** Dienste/Module, die physische Isolation wollen, holen
    ihre Connection über den Resolver. Ein vollautomatisches Re-Routing **aller**
    `get('default')`-Zugriffe (statt opt-in) ist der Produktiv-Vollausbau.
  - **Voraussetzung je Tenant-DB (Ops):** DB anlegen + Schema-/Rollen-Bootstrap
    (analog `schema_init`/`db_provision_app_role` der Haupt-DB), dann
    `tenant_db_provision`.

## Was der Betrieb beisteuert (nicht Core)

- **Load-Balancer / Reverse-Proxy** vor mehreren `web`/`core`-Instanzen (Sticky
  Sessions nicht nötig dank DB-Sessions). Trusted-Proxy-Konfig für korrekte
  Client-IP (Rate-Limit).
- **Horizontale Skalierung**: mehrere stateless `core`-Instanzen + mehrere
  `worker` (Outbox ist SKIP-LOCKED-sicher; periodische Tasks advisory-locked).
- **Read-Replicas**: Lese-Last auf Replicas (Connection-Routing-Hook im Core
  möglich; Replica-Betrieb/Failover ist Ops).
- **Broker-Betrieb**: Redis (für `queue.transport=redis`) bzw. ein größerer Broker
  als externe, betriebene Komponente.
- **WAL-Archivierung / PITR**: Point-in-Time-Recovery ist DB-Server-/Storage-Konfig
  (Runbook); der Core liefert zusätzlich logische Backups + Wiederherstellungspunkte
  (`BACKUP.md`).
- **Observability-Backend & Alerting**: Prometheus/Grafana/Loki/Jaeger, Alert-Regeln,
  On-Call/Paging — der Core **emittiert** die Telemetrie, die Stacks **konsumieren**.
  - **OTLP-Metrik-Export** (`OTEL_EXPORTER_OTLP_ENDPOINT`) und **Health-Alert-Webhook**
    (`health.alert_url`) laufen über den gehärteten Egress mit **SSRF-Schutz**: private/
    reservierte Ziel-IPs sind per Default **blockiert**. Interne Collector/Empfänger
    (z. B. `http://otel-collector:4318`, `http://alertmanager.internal`) müssen daher
    in `core.http.egress.allowlist` aufgenommen (oder `core.http.egress.allow_private`
    gesetzt) werden — sonst schlägt der Versand fehl. Fehlversuche werden als
    **Warning geloggt** (`[otlp-export]` / `[health-alert]`), laufen aber fehlerisoliert
    (kein Worker-Abbruch).

## Empfohlene Topologie (Richtwert)

```
            ┌── web (n)  ──┐
client ──LB─┤   core (n)   ├── db (primary)  ── (read replicas)
            └── worker (m) ┘     │
                  │              └── (DB-pro-Mandant: tenant_<key>-DBs, optional)
              redis (optional, queue.transport=redis)
```

Single-Org/On-Prem bleibt ein Sonderfall davon: eine Instanz je Rolle, Pool-Modell,
`queue.transport=db` — unverändert lauffähig ohne die obigen externen Komponenten.
