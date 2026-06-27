# KI-Completion als nahtlose Extension — Migrations-Design (Entwurf)

> **Status: ENTWURF, nicht umgesetzt (Stand 27.06.2026).** Reine Design-/Planungs-Referenz.
> Kein Implementierungscode. Erarbeitet + adversarial geprüft auf Basis des aktuellen Core.

## 1. Ziel & Motivation

KI/LLM ist heute Core-intern. Sie soll umgebaut werden, sodass **Chat/Completion +
Multi-Provider (mehrere fremdgehostete *und* lokal installierte LLMs) + Usage-Limits/
Kostenkontrolle** in eine **eigenständige, lizenzierte Extension** wandern. Treiber:

- **opt-in** — wer keine KI braucht, installiert die Extension nicht (kein KI-Code, kein Provider).
- **Monetarisierung** — separat verkaufbar (`requires_license`, `LicenseService`, Marketplace;
  AGPL-§7-Ausnahme in `LICENSING.md` deckt In-Process-Linking ab).
- **Flexibilität** — Provider/Modelle/Limits iterieren unabhängig vom Core-Release.

**Harte Anforderung:** Bestehende Module (`knowledgebase`, `ticketing`, Connector) werden
**nicht** angepasst — die Extension integriert sich nahtlos. (Deckt sich mit der Core-Boundary:
die Core-Session editiert keinen Modul-Code.)

## 2. Grundentscheidung: Hybrid-Schnitt (Completion ≠ Embeddings)

KI zerfällt in zwei unterschiedlich gekoppelte Teile. Nur der **ungekoppelte** Teil wird ausgelagert:

| | **Chat/Completion** | **Embeddings / semantische Suche** |
|---|---|---|
| Core-Konsument heute | **keiner** (`core.ai.complete` ist nur ein Platzhalter-Contract) | hart verdrahtet in `SearchService.hybrid → EmbeddingService.semantic` |
| Schema-Kopplung | keine | `core.embeddings`, **`vector(1536)` fest**, HNSW-Index |
| Hot-Path | nein | ja (jede Suchanfrage + jedes Indexieren) |
| **Entscheidung** | **→ Extension** | **→ bleibt Core** |

Embeddings sind such-kritisch, schema-gebunden (Dimension 1536) und auf dem heißen Pfad —
dort hat eine Auslagerung den höchsten Aufwand und geringsten Nutzen. Completion ist
ungekoppelt (kein Core-Feature ruft es heute) — der ideale Auslagerungs-Schnitt.

## 3. Seamless-Mechanik: Fassade mit interner Delegation

`App\Service\Ai\AiGateway` bleibt die **byte-stabile Fassade** im Core. Module rufen weiter
`new AiGateway()->complete(...)` (verifiziert: `knowledgebase/src/Ai/AiAssistService.php`
instanziiert direkt). Intern delegiert der Gateway an **einen** von der Extension registrierten
Provider; fehlt die Extension, verhält er sich exakt wie heute.

Stabil bleiben: Klasse, FQN (`App\Service\Ai\AiGateway`), Namespace, 2-arg-Default-Konstruktor,
`AiException`, und alle 5 Public-Methoden — `enabled():bool`, `embedEnabled():bool`,
`complete(string,array):string`, `chatMessages(array,array):array{text,raw}`, `embed(string):list<float>`.

### 3.1 Der kritische Punkt: `enabled()` ist der Gatekeeper

Das Modul prüft **`$ai->enabled()` BEVOR** es `complete()` ruft. Würde nur `chatMessages()`
delegiert, liefe die Extension ins Leere: `enabled()` läge weiter an der alten Core-Settings-
Logik und gäbe `false` zurück, das Modul riefe nie an.

> **Konsequenz: `enabled()` UND `embedEnabled()` müssen ebenfalls den Registry-Provider
> konsultieren.** Damit — und nur damit — hält die Seamless-Garantie.

### 3.2 Delegationsfluss

1. `chatMessages()` (der einzige Pfad, über den `complete()` läuft — `AiGateway.php:51`) fragt
   zuerst `ContractRegistry::resolveProvider('core.ai.complete')`.
2. Bei Treffer: Aufruf der Extension-Provider-Klasse via `ContributionRuntime->call(... 'handle' ...)`
   mit `handle(array):array`. Input `{prompt|messages, opts}`, Output `{text, raw, usage?}`.
   `AiGateway` extrahiert `['text']` → Rückgabetyp unverändert.
3. Bei `null` (keine Extension aktiv/lizenziert): unveränderter Legacy-Fallback; `complete()`
   wirft `AiException` genau wie heute bei fehlender Konfiguration. Das Modul fängt das ab
   (`catch Throwable`) und degradiert auf `null/[]` — Fail-safe bleibt erhalten.
4. `enabled()`/`embedEnabled()` spiegeln denselben Resolve (siehe 3.1).

### 3.3 Slot-Exklusivität ist verträglich

`core.ai.complete` ist ein SERVICE-Contract; `assertTypeMatch` (`ContractRegistry.php:239`)
erlaubt `TYPE_PROVIDER`, ein Unique-Index erzwingt **genau einen** aktiven Provider. Die
Extension ist **der eine** Multiplexer-Provider und routet **intern** zu beliebig vielen
Sub-LLMs (reine Extension-Geschäftslogik, kein Registry-Konzept). Der Core registriert sich
**nie selbst** als Provider — die Legacy-Enum-Logik bleibt ein lokaler Fallback *innerhalb*
`AiGateway`, keine Provider-Registrierung.

### 3.4 `embed()` unberührt

`embed()` läuft **nicht** durch `chatMessages()`/`resolveProvider()` und bleibt Core-built-in
(1536). `EmbeddingService` und `SearchService.hybrid()` ändern sich nicht — die semantische
Suche ist von der Completion-Migration vollständig entkoppelt.

## 4. Was bleibt im Core / was wandert

| Komponente | Ort | Begründung |
|---|---|---|
| `AiGateway` (Fassade) | **Core** | Module hängen direkt dran; intern auf Delegation umgebaut |
| `AiException`, `enabled()`/`embedEnabled()` | **Core** | Teil des Modul-Vertrags; `enabled()` muss mit-delegieren |
| `EmbeddingService` + `core.embeddings` (1536) + `SearchService` | **Core** | such-kritisch, schema-gekoppelt; kein Modul-Embedding-Interface |
| Legacy-Provider (OpenAI/Anthropic/xAI/Google) | **Core** (Fallback, faktisch deaktiviert) | hält die Suite grün; perspektivisch entfernbar |
| Multi-Provider-Routing, lokale LLMs, Modellwahl | **Extension** | der „eine" Multiplexer-Provider |
| Usage-/Token-/Kosten-Metering + Per-Tenant-Limits | **Extension** | `CacheStore::increment` + tenant-scoped `ai_usage`-Tabelle; Limit-Check **vor** dem Call |
| Provider-/Limit-Settings | **Extension-`config_schema` → `core.tenant_modules.config`** | `SettingsCatalog::DEFINITIONS` ist hartcodiert, nicht erweiterbar; `config_schema` ist der Decision-185-konforme Weg |
| Local-LLM-Egress | **Extension nutzt Core-`EgressClient`** (+ optional Core-Setting `loopback_allowed`) | nie `new PDO`/curl (Capability-Gate) |
| Lizenz-Gate | **Extension-Manifest** (`requires_license=true`) + Core-`LicenseService` | `ModuleLifecycle::activate()` blockiert ohne Lizenz |

## 5. Vorbedingungen

| Vorbedingung | Blockierend | Anmerkung |
|---|---|---|
| `AiGateway`-Delegations-Hook inkl. **`enabled()`** | **ja** | Kern-Enabler; ohne ihn ignoriert der Core jede Registrierung (§3.1) |
| Settings via Manifest `config_schema` (kein `SettingsCatalog`-Edit) | **ja** (für Settings-UI) | `DEFINITIONS` ist hartcodierte private const |
| `core.embeddings` RLS-Härtung | **nein** | bewusste Architektur (Decision 185/E110, app-seitiger `tenant_id`-Filter); Embeddings bleiben Core |
| `core.ai.complete` Payload-Shape/Version festigen | nein | heute `input_spec`/`output_spec` = NULL; in S0 dokumentieren |
| Local-LLM-Egress (`loopback_allowed`) | nein | nur für Feature „lokale LLMs"; hosted Provider gehen ohne |
| Metering-Sichtbarkeit (Dashboard) | nein | Variante A ohne Core-Edit möglich |
| Lizenz-Gate | nein | `LicenseService` vorhanden, nur Manifest-Flag |

## 6. Stufenplan

Suite bleibt bei **jeder** Stufe grün; `touches_modules = false` durchgängig.

- **S0 — Contract festigen.** `core.ai.complete` (existiert, v1.0.0, provider-fähig) eine
  explizite Payload-Shape geben: Input `{prompt|messages, opts}`, Output `{text, raw, usage?}`.
  Rein deklarativ. *Risiko: sehr niedrig.*
- **S1 — Fassaden-Refactor (Kern-Enabler).** In `AiGateway`: `chatMessages()` **und
  `enabled()`/`embedEnabled()`** stellen `ContractRegistry::resolveProvider('core.ai.complete')`
  voran; bei Treffer Aufruf via `ContributionRuntime` (`handle(array):array`), sonst
  unveränderte Legacy-Logik. `try/catch → AiException`, kein neuer Exception-Typ nach außen.
  Neuer Core-Test mit Fake-Provider; Modul-Suiten (unangetastet) bleiben grün. *Risiko: mittel
  (zentraler Pfad), mitigiert durch Fallback.*
- **S2 — Sicherer Local-LLM-Egress (optional).** Neues Setting
  `core.http.egress.loopback_allowed` (bool, default `false`): erlaubt gezielt `127.0.0.1/::1`
  **ohne** den globalen SSRF-Kill-Switch `allow_private` zu kippen und **ohne** RFC1918 zu
  öffnen; IP-Pinning bleibt aktiv. (Nicht über `allowlist` — die umgeht den IP-Check,
  `EgressClient.php:234`.) *Risiko: mittel (security), mitigiert: Default off, loopback-only.*
- **S3 — Extension-Gerüst.** Neues lizenziertes Modul (außerhalb Core): Manifest mit
  `services_registered=[{core.ai.complete, PROVIDER, Multiplexer}]`, `config_schema` für
  Provider/Modell/Limits, Multiplexer-Stub (`handle()` → ein Cloud-Provider via Core-`EgressClient`).
  Kein Core-/Modul-Edit. *Risiko: niedrig.*
- **S4 — Multi-Provider + Metering + Limits.** Internes Routing (hosted + lokal),
  `CacheStore::increment('ai:cost:<tenant>:<month>')` + `ai_usage`-Tabelle (Migration in der
  Extension), **Limit-Check transaktional vor dem Call** (Überschreitung → `reject`/`AiException`
  → Modul degradiert auf `null/[]`). Lokale LLM nur bei S2-Flag. *Risiko: mittel (Race/Overspend),
  mitigiert: atomarer Increment, Prüfung vor Call; alle Usage-Queries request-tenant-scoped.*
- **S5 — Verbrauchs-Sichtbarkeit.** *Variante A (empfohlen, kein Core-Edit):* Extension bringt
  eigene `web_route` `/admin/ai-consumption`, liest `ai_usage`. *Variante B (eleganter, Core-Edit):*
  generischer `core.collector.consumption`-Contract, in den sich die Extension einklinkt — nur
  wenn KI-Verbrauch im **bestehenden** Inc-7-Dashboard erscheinen soll (`TenantConsumptionService::summary()`
  ist heute hartcodiert).

## 7. Stolpersteine (adversarial geprüft)

1. **`enabled()`-Delegation** (kritisch) — siehe §3.1; ohne ihn ist alles andere wirkungslos.
2. **Provider-Auflösung ist tenant-gated** (`gateByTenantModules`, `ContractRegistry.php:379-413`):
   Die Extension muss für den Mandanten aktiviert sein, sonst liefert `resolveProvider()` `null`.
   Die Tenant-Scoping-Politik muss zwischen `enabled()`-Check und Call **konsistent** sein.
3. **`type=extension` erzwingt `extends_main_module`** (`ModuleManifest::validate`): eine
   freischwebende Extension ist ungültig → siehe offene Entscheidung 1.
4. **Consumption-Variante B = echter Core-Edit** (`TenantConsumptionService::summary()` hartcodiert).
   Variante A vermeidet das.
5. **Lizenz greift bei `activate`, nicht pro Call** — ein aktives Modul läuft nach Lizenzablauf
   weiter bis zum nächsten Lifecycle-Check; harte Durchsetzung bräuchte Online-Enforcement.
6. **`allowlist` umgeht den IP-Check** — Local-LLM nur über `loopback_allowed`, nicht `allowlist`.

## 8. Offene Entscheidungen

1. **Verankerung:** KI ist quer-schneidend (KB *und* Ticketing). Empfehlung: **eigenständiges
   Main-Modul „KI-Dienste"** statt Anker an knowledgebase — umgeht den `extends_main_module`-Zwang
   sauber (zu bestätigen: Main-Modul darf Service-Contract-Provider sein; Decision 181 spricht dafür).
2. **Embedding-Dimension:** Embeddings bleiben vorerst Core/1536. Lokale **Embedding**-Modelle
   mit abweichender Dimension wären ein separater Core-Schema-Schritt — bewusst **nicht** in diesem Plan.
3. **Limit-Einheit:** Token oder USD (fairer bei Provider-Wechsel, aber Pricing-Pflege)?
4. **Provider-Keys:** env-Var (heutiger Stil) vs. Core-`SecretCipher` (Operator-Keys verschlüsselt in DB)?
5. **Lokale LLMs Erstklass?** Dann S2 (`loopback_allowed`); sonst nur hosted (kein Core-Touch).
6. **Ollama-Auth:** Loopback-Vertrauen ausreichend, oder API-Key/Auth-Proxy verpflichtend?
7. **Contract-Drift:** harte Shape-Validierung in `handle()` + Version-Bump, um Provider-Drift
   (neue `opts`/`usage`) zu erkennen? (betrifft S0)

## 9. Netto

Drei kleine Core-Eingriffe — **S0** (Contract), **S1** (Fassade inkl. `enabled()`), optional
**S2** (Egress) — tragen die gesamte Auslagerung; alles Weitere lebt in der Extension. **Kein
Modul wird angefasst.** Die Seamless-Garantie hält, sobald `enabled()`-Delegation +
Tenant-Scoping-Politik eingebaut sind.

## 10. Verweise

- Architektur-Entscheidung Core-vs-Extension: siehe Chat-Analyse (2 Workflows, 14 Agenten, am Code belegt).
- Kapitel 23.16 (In-Process + Capability-Gate, Entscheidung 187), `LICENSING.md` (AGPL §7),
  Decision 181/184/185, Inc 7d/7e (Verbrauchs-Dashboards).
- Relevante Dateien: `core/src/Service/Ai/AiGateway.php`, `EmbeddingService.php`,
  `core/src/Service/Search/SearchService.php`, `core/src/Service/Registry/ContractRegistry.php`,
  `core/src/Service/Http/EgressClient.php`, `core/src/Service/Settings/SettingsCatalog.php`.
