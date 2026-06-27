# KI als Modul über Konnektoren — Migrations-Design (Entwurf)

> **Status: ENTWURF, nicht umgesetzt (Stand 27.06.2026).** Reine Design-/Planungs-Referenz,
> kein Implementierungscode.
>
> **Diese Fassung ersetzt den früheren „Seamless-Fassaden"-Ansatz.** Der war architektonisch
> falsch: eine Core-`AiGateway`-Fassade, die intern an ein AI-Modul delegiert, ist ein
> **verdecktes Modul→Modul-Gespräch** — und Fertura erlaubt Modul↔Modul **nur über einen
> Konnektor** (Decision 183). Sobald KI ein Modul ist, darf der Core **keinen** modul-gerichteten
> KI-Einstiegspunkt mehr anbieten.

## 1. Ziel & bindende Regel

KI/LLM (Chat/Completion + Multi-Provider hosted+lokal + Usage-Limits/Kostenkontrolle) wandert
in ein **eigenständiges, lizenziertes AI-Modul**, das die KI-Einstiegspunkte bereitstellt.
Konsumierende Module erreichen es **ausschließlich über dedizierte Konnektoren** — ein
**Ticket-AI-Konnektor** und ein **KB-AI-Konnektor**. Der Core steigt aus dem modul-gerichteten
KI-Geschäft aus; **Embeddings für die Core-Suche bleiben Core-intern**.

**Bindende Regel:** Module reden nicht direkt miteinander — Modul↔Modul **nur** über einen
Konnektor (`type=integration`, Blattknoten, stellt **keine** Contracts bereit, Decision 183).
**Core↔Modul** (Core löst einen modul-bereitgestellten Contract auf) ist erlaubt und **nicht
dasselbe** wie Modul↔Modul.

**Zusatz-Prinzip (verbindlich):** KB und Ticketing sind **standalone** — sie laufen ohne den
AI-Konnektor vollständig (KI ist keine Abhängigkeit). Sie bieten **generische, fähigkeits-neutrale
Erweiterungspunkte** an (KEINE `ai.*`-spezifischen Slots), die ein Host-Modul **bereitstellt** und
**beliebige** Konnektoren **konsumieren** — der AI-Konnektor ist nur **ein** Andocker; ein
Grammatik-/Übersetzungs-/Klassifizierungs-Konnektor kann dieselben Punkte nutzen. Die
Andockpunkte = Erweiterungspunkte (Event-/Collector-Slots).

**Tragende Annahme — bestätigt:** Ein `type=main`-Modul **darf** einen Service-Contract
bereitstellen (Decision 153, Kap. 26.4.2 „Contract-Anbieter (Main- und Extension-Module)";
Decision 181 bestätigt es für Extensions „mit denselben Regeln wie Main-Modul-Contracts").
Main-Module dürfen aber **keine** fremden Contracts konsumieren (Decision 153) → das AI-Modul
hat `contracts_used=[]` und nutzt nur Core-Infrastruktur.

## 2. Topologie

```
            ┌─────────────── Core ───────────────┐
            │ Registry · Outbox · EgressClient ·  │   bietet KEINE modul-gerichteten
            │ Embeddings(1536)+Suche INTERN       │   KI-Einstiegspunkte mehr
            └───────▲────────────────────▲────────┘
        Collector/  │ Event-Dispatch     │ ai.complete (Core vermittelt; Konnektor konsumiert)
        Listener    │                    │
   ┌────────────────┴───┐   ┌────────────┴───────────┐   ┌──────────────────┐
   │ Ticket-AI-Konnektor│   │ KB-AI-Konnektor        │──▶│ AI-Modul (main)  │
   │ (integration,Blatt)│   │ (integration,Blatt)    │   │ stellt ai.complete│
   │ hört Ticket-Events │   │ hört KB-Events         │   │ bereit; Multi-    │
   │ ruft ai.complete   │   │ ruft ai.complete       │   │ Provider+Limits   │
   └─────────▲──────────┘   └──────────▲─────────────┘   │ requires_license  │
   Events +  │ Panel         Events +  │ Panel           └──────────────────┘
   Panel-Slot│               Panel-Slot│
   ┌─────────┴──────────┐   ┌──────────┴─────────────┐
   │ Ticketing (main)   │   │ KnowledgeBase (main)   │   ← wissen NICHTS von KI
   └────────────────────┘   └────────────────────────┘
```

| Knoten | Typ | Rolle | stellt bereit | konsumiert |
|---|---|---|---|---|
| **AI-Modul** (`ki_dienste`) | Main (`requires_license`) | quer-schneidende KI-Authorität; Multiplexer über LLM-Provider | `ai.complete` (service, `error_behavior=reject`), opt. `ai.chat` | **nur Core-Infra**: `EgressClient`, `SecretCipher`, `config_schema→tenant_modules.config`, `CacheStore`, `LicenseService`, `AuditLogger` |
| **Ticket-AI-Konnektor** | Connector (leaf) | brückt Ticketing→AI, nicht-invasiv | **nichts** (`contracts_provided=[]`) | `ai.complete`; `events_registered: ticketing.ticket.*`; `collectors_registered: ticket_view_panels`, `core.collector.anonymize` |
| **KB-AI-Konnektor** | Connector (leaf) | brückt KB→AI | **nichts** | `ai.complete`; opt. `knowledgebase.get_article`; `knowledgebase.article.*`; `article_view_panels`, `anonymize` |
| **Core** | Core | Vermittlung; **kein** KI-Einstiegspunkt | Registry/Outbox/Egress/…; **intern**: `EmbeddingService`+`core.embeddings(1536)`+`SearchService.hybrid` | Modul-Contracts zentral (Core→Modul erlaubt) |
| **Ticketing** | Main | Event-Publisher + Panel-Slot-Anbieter | `ticket_view_panels`, `ticketing.ticket.*` Events | nur Core-Contracts; **kein** AI-Contract direkt |
| **KnowledgeBase** | Main | heute KI via Direktimport (`new AiGateway()`) | `search`, `get_article`, `article_view_panels`, `article.*` | **Ziel:** via KB-AI-Konnektor entkoppelt (→ KB-Codeänderung) |

## 3. Konnektor-Mechanismus (Vorbild: `ticketing_knowledgebase_bridge`)

Verifiziert am Referenz-Konnektor (`contracts_provided`/`resolvers`/`services` alle leer). Zwei
Schichten, **kein** Modul→Modul-Direktaufruf:

1. **PUSH (Event-Listener, asynchron — der primäre regelkonforme Pfad):** Das Host-Modul
   emittiert via `OutboxPublisher` transaktional ein **Fakt-Event** (`ticketing.ticket.created`,
   `knowledgebase.article.published`) — nicht „an jemanden", sondern als Tatsache. Der
   Outbox-Worker findet den registrierten Konnektor-Listener über die `ContractRegistry` und ruft
   `handle(payload, context)`. Der Konnektor liest IDs, holt Kontext über einen **Read-Contract**
   (`knowledgebase.get_article`), baut den Prompt, ruft das AI-Modul über
   `CapabilityHandle.invoke(['ai.complete', {prompt, context}])` und schreibt das Ergebnis in
   seine **eigene** tenant-scoped Kopplungstabelle (RLS, Event-ID UNIQUE = idempotent bei
   at-least-once) — **nie** in KB/Ticketing-Tabellen.
2. **PULL (Collector-Panel, synchron — für die Anzeige):** Das Host-Modul rendert eine Ansicht
   und fragt **selbst** den Core nach `*.view_panels`-Collectors. Der Core liefert anonym
   Klasse+Modul-Key; das Host-Modul instanziiert das Konnektor-Panel und ruft `panels(context)`.
   Das Panel liest die vorab berechneten KI-Ergebnisse aus der Konnektor-Kopplungstabelle und
   reicht den Benutzer-Kontext durch (RLS schützt vor Cross-Tenant/Permission-Leak).

Der Konnektor **hört** (Events), **injiziert sich** (Collector) und **konsumiert** `ai.complete`
(CapabilityHandle) — alles über Core-Infrastruktur. Die gebrückten Module bleiben Fremde.
Fehlerverhalten: enhancing-not-gating (`CapabilityRejected`/`Throwable` → geschluckt →
Degradation auf neutral).

## 4. Modul-Änderungs-Verdikt: **teilweise** (ehrlich)

Die „seamless, byte-stabile"-Garantie des Vorgänger-Plans ist **tot** — sie verdeckte
Modul→Modul-Verkehr.

- **Ticketing: keine Änderung.** Nutzt heute **null** KI (grep: 0 Treffer auf
  `AiGateway`/`complete`/`embed`). Der Ticket-AI-Konnektor hängt sich rein additiv an ohnehin
  existierende Lifecycle-Events + den Panel-Slot — **vorausgesetzt KI bleibt streng additiv**
  (Anzeige-Panel + async Anreicherung; kein KI-Schritt wird Teil eines Pflicht-Flows).
- **KB: muss geändert werden.** Siehe §5.

## 5. Das KB-Problem — gelöst: synchrone Inline-KI ist strukturell nicht möglich (Spike-Ergebnis)

KBs heutige KI ist **synchron und inline** (`AiAssistService` → `new AiGateway()->complete()`:
`generateTeaser`, `rephrase`, `draftFromBullets`, `translateDraft`, `suggestTags`): Klick →
**sofortiger** Draft. Der KB-UX-Spike hat verifiziert, dass dieses synchrone Muster in der
Konnektor-Topologie **nicht regelkonform erhaltbar** ist. Zwingende Kette:

1. KB **bietet** generische Erweiterungspunkte an; der Konnektor **konsumiert** sie (Datenfluss
   einseitig: Host stellt bereit → Konnektor verbraucht).
2. Ein Konnektor (Blattknoten) **stellt nichts bereit** (Decision 183 / Consumer-only 23.5.5) →
   kann **kein** synchroner Resolver sein, den KB aufruft.
3. KB darf das AI-Modul **nicht** direkt rufen (Modul→Modul).
4. Core bietet **keinen** KI-Einstiegspunkt → auch kein synchroner Core-Resolver.

→ **Kein regelkonformer synchroner Pfad.** Die KI-Berechnung läuft zwingend **asynchron**: Der
Editor emittiert einen **generischen** Trigger (`knowledgebase.article.assist_requested` mit
Draft-Text + Aktionsname — **nicht** `ai.*`), der Konnektor-Listener ruft das AI-Modul, legt das
Ergebnis in seiner eigenen Tabelle ab, KB zeigt es via **generischem** Vorschlags-Panel/SSE.

*(Der Spike fand als saubersten Sync-Pfad „KB-eigene `api_route` → Core-`AiGateway`" — aber das
hält **nur**, solange KI ein Core-Service bleibt (P11). Das widerspricht dem Ziel „KI als Modul,
Core ohne KI-Einstiegspunkt" und ist daher hier verworfen. Die Konnektor-`web_route`-Variante ist
technisch nicht hart gesperrt, verletzt aber Consumer-only — siehe §10 Hand-off.)*

**UX-Konsequenz (ehrlich):** „Klick → sofortiger Draft" wird zu „Klick → *generiere…* → Vorschlag
erscheint (Sekunden später) → übernehmen". KBs `use App\Service\Ai\AiGateway` fällt weg, generische
Slots + async Vorschlags-UI kommen rein → **KB ist nicht byte-unverändert**, Umsetzung per
**Hand-off ans KB-Repo**. Das ist die logische Folge der Regeln, nicht ein Designfehler.

## 6. Core-Ausstieg (chirurgisch)

**Entfernen (modul-gerichtete Einstiegspunkte):**
- Contracts `core.ai.complete`/`core.ai.embed` — heute **unverdrahtete DB-Karteileichen**
  (`ModuleContractsCommand.php:27-28` mappt auf den Core-Service `AiGateway`; **kein**
  `resolveProvider`/`handleFor`-Wiring existiert) → DELETE ist risikoarm.
- Die modul-importierbare Completion-Fassade `AiGateway::complete/chatMessages` zurückbauen.
- `MODULE_DEVELOPMENT.md` + `ModuleContractsCommand` Capability-Liste anpassen.

**Core-intern behalten (kein Einstiegspunkt):** `EmbeddingService` + `core.embeddings(1536)` +
HNSW-Index + `SearchService.hybrid`. Core-Eigenversorgung der Suche ≠ modul-gerichteter
Einstiegspunkt.

> **Chirurgischer Vorbehalt (Reviewer):** `embed()` und `complete()` teilen sich **dieselbe**
> `AiGateway`-Klasse + Provider (`EmbeddingService.php:19-21,41,90`). Der Completion-Rückbau darf
> den **Core-internen Embed-Pfad nicht mitreißen** — sonst fällt die semantische Suche still auf
> FTS. Nötig: interner `EmbeddingGateway` (bleibt) vs. ausgelagerte Completion (geht).

## 7. Embeddings-Entscheidung

**Empfehlung: Embeddings bleiben Core-intern (Option i).** Such-kritisch, Hot-Path,
schema-gebunden (`vector(1536)` fest). Core-interne Nutzung ist **kein** modul-gerichteter
Einstiegspunkt → verletzt „Core bietet keine KI-Einstiegspunkte" nicht. Graceful Degradation
existiert (kein Embedding-Provider → `shouldEmbed=false` → reine FTS).

*Option ii (Core→AI-Modul `ai.embed`-Contract, laut Regel erlaubt):* flexible Dimension/Modelle,
aber Hot-Path wird Registry-abhängig, Tenant-Scoping muss eisern konsistent sein (`core.embeddings`
ist nur app-seitig tenant-gefiltert), abweichende Dimension erzwingt pgvector-Migration — viel
Komplexität auf dem kritischsten Pfad bei geringem Nutzen. **Verworfen** (bewusst späterer Schritt).

## 8. Vorbedingungen

| Vorbedingung | Blockierend | Anmerkung |
|---|---|---|
| `type=main` darf Service-Contract bereitstellen | **erledigt** | Decision 153 / Kap. 26.4.2 — bestätigt |
| Eigener Modul-Contract `ai.complete` (`error_behavior=reject`) im AI-Modul | **ja** | ersetzt das entfernte `core.ai.complete`; muss VOR den Konnektoren existieren |
| **Eigener AI-Egress-Timeout** (`core.ai.timeout_seconds` / per-Call-Timeout) | **ja** | LLM-Calls laufen heute in den globalen `EgressClient`-Timeout (Default **10s**, `AiGateway.php:30`, `OpenAiProvider.php:37`); 20–30s-Drafts scheitern **still** (`AiException`→`null`); globales Anheben dehnt **alle** Egress-Calls. AI braucht eigenen Timeout — kleine Core-Erweiterung |
| KB-UX = **async** (kein sync inline) | **ja (entschieden)** | strukturell erzwungen (§5); generischer `assist_requested`-Trigger + Vorschlags-Panel/SSE |
| Konnektor-Kopplungstabellen: tenant-scoped RLS + Event-ID-UNIQUE | **ja** | Konnektor schreibt nie in KB/Ticketing-Tabellen; Idempotenz bei at-least-once |
| `AnonymizeContributor` pro Konnektor (`core.collector.anonymize`) | **ja** | KI-Ergebnisse aus Ticket-/Artikeltext → DSGVO |
| Provider-Keys/Settings via `SecretCipher` + `config_schema→tenant_modules.config` | nein | `SettingsCatalog::DEFINITIONS` ist hartcodiert, nicht erweiterbar |
| Egress für lokale LLMs (`core.http.egress.loopback_allowed`) | nein | nur falls lokale LLMs Erstklass; Default off, loopback-only, IP-Pinning aktiv |
| Metering/Limit-Check transaktional VOR dem AI-Call | nein | per-Tenant Kostenkontrolle; atomarer `CacheStore::increment` |

## 9. Stufenplan

- **S0 — Contract-Shape `ai.complete` + Sequenz.** Input `{prompt|messages, opts}`, Output
  `{text, raw, usage?}`; Versionierung. Deklarativ. `touches_modules=false`. *Risiko: sehr niedrig.*
- **S1 — AI-Modul (`type=main`) mit `ai.complete`-Provider.** Eigenständiges lizenziertes Modul,
  Multiplexer über Provider via Core-`EgressClient`, Settings via `config_schema`, Keys via
  `SecretCipher`. Kein Core-/Modul-Edit. *Risiko: niedrig.*
- **S2 — Core-Exit.** `core.ai.complete/embed` (DB-Karteileichen) entfernen; öffentliche
  Completion-Fassade zurückbauen; **Embed-Pfad chirurgisch im Core belassen**. Reihenfolge: erst
  S1 + Konnektoren bereit. *Risiko: niedrig–mittel (überzeichnet im Roh-Plan; trifft real nur KB).*
- **S3 — Ticket-AI-Konnektor.** Blattknoten; hört Ticket-Events, ruft `ai.complete`, injiziert
  Insight-Panel; eigene Kopplungstabelle. **Touched Ticketing nicht.** *Risiko: niedrig.*
- **S4 — KB-AI-Konnektor + KB-Entkopplung (TOUCHT KB).** KB-internen `new AiGateway()` entfernen,
  Inline-Flow auf Panel/async umstellen **oder** KB-KI einstellen; neuer Konnektor.
  `touches_existing_modules=true`. *Risiko: hoch (UX-Bruch; KB-Repo-Hand-off).*
- **S5 — Metering, Limits, Verbrauchssichtbarkeit.** Per-Tenant Token/USD-Limits im AI-Modul
  (transaktionaler Check vor Call), Dashboard (eigene `web_route` oder `core.collector.consumption`).
  *Risiko: mittel (Race/Overspend; atomarer Increment).*

## 10. Konnektor-Hausaufgaben (Reviewer, teils blockierend)

- **Event-Schleifen-/Kosten-DoS-Schutz:** Konnektor schreibt nur in **eigene** Tabelle, feuert
  **kein** Event, das ein Main-Modul hört (sonst Outbox-Schleife × LLM-Kosten pro Hop).
  Gerichtetheit + Hop-Count/TTL als blockierende Precondition für S3/S4.
- **Idempotenz VOR den AI-Call** ziehen (Event-ID-Guard zuerst), sonst Doppelverbrauch bei Retry.
- **Lizenz-Degradation:** fehlt die AI-Lizenz → `CapabilityRejected` → Panel neutral, kein 500
  (Test verankern). `integration_relations` müssen AI-Modul **und** gebrücktes Main hart führen.
- **Health-Collector** des AI-Moduls (`core.collector.health`).
- **FPM-Pool/Concurrency:** Jeder LLM-Call belegt einen Worker bis zum Timeout. Trigger-Endpunkte
  als rate-limitbare `api_route` (`/api/*` → `ApiRateLimitMiddleware`) + Per-User-Concurrency-Cap,
  sonst Pool-Erschöpfung bei parallelen Redakteuren. Streaming (Token-für-Token) ist heute **nicht**
  verfügbar (`LlmProviderInterface` ohne Stream, `EgressClient` buffert voll) → keine TTFT-Mitigation.
- **Hand-off (Core-Boundary): Validator-Lücke.** Der Manifest-Validator blockt für
  `type=integration` nur `contracts/resolvers/services`, **nicht `web_routes`**
  (`ModuleManifest.php:323`); `WebRouteRegistry`/`ModuleWebController` dispatchen ohne Typ-Prüfung.
  Ein Blattknoten *könnte* so einen Einstiegspunkt anbieten (verletzt Consumer-only 23.5.5). →
  Eigener Core-Hand-off: `web_routes` für `type=integration` im Validator verbieten.

### Übersehene Kopplungen (mitwandern / berücksichtigen)

- `AiStatusPage` (`knowledgebase/src/Web/Admin/AiStatusPage.php`) nutzt `AiGateway` direkt → beim
  KB-Umbau mit umstellen/entfernen.
- `AiAssistServiceTest` (instanziiert ohne Mocks) → Test-Migration.
- KBs `ai.max_input_chars`-Kostenschutz (`AiAssistService.php:108`) → muss in den Konnektor.
- `ModuleContractsCommand.php:27-28` + `MODULE_DEVELOPMENT.md` listen `core.ai.*` als Capability →
  beim Core-Exit anpassen.

## 11. Offene Entscheidungen / Gates

1. ~~**KB-UX-Spike (Gate vor S4)**~~ **ERLEDIGT:** Sync inline ist strukturell nicht regelkonform
   (§5) → KB-KI wird **async** (generischer `assist_requested`-Trigger + Vorschlags-Panel/SSE).
   Verbleibende Produkt-Entscheidung: kurze Funktionen (Teaser/Tags) async akzeptabel, oder einzelne
   Funktionen vorerst einstellen?
2. **AI-Contract-Granularität:** generisches `ai.complete` (Konnektoren bauen Prompts) vs.
   domänenspezifische Contracts (`ai.analyze_ticket`) — letztere bräuchten Domänenwissen im
   AI-Modul, eher Konnektor-Aufgabe → Tendenz: generisch.
3. **Provider-Keys:** env (heute) vs. Core-`SecretCipher` (verschlüsselt, Operator-verwaltet).
4. **Lokale LLMs Erstklass?** Dann `loopback_allowed` (S-Egress); sonst nur hosted.
5. **Limit-Einheit:** Token vs. USD (fairer bei Provider-Wechsel, Pricing-Pflege).
6. **Lizenz-Enforcement** greift bei `activate`, nicht pro Call — akzeptabel, oder Online-Enforcement?

## 12. Netto

Die Konnektor-Topologie ist die saubere, regelkonforme Lösung — **Ticketing bekommt KI geschenkt**
(additiver Konnektor), das **AI-Modul ist sauber lizenzierbar**. Der Preis: **KB muss angefasst
werden** (synchrone Inline-KI passt nicht ins async-Konnektor-Modell → KB-UX-Entscheidung). Das
ist kein Designfehler, sondern die Konsequenz der Regel „Module reden nur über Konnektoren".
Vor jeder Zeile Code: **KB-UX-Spike** fahren; der Rest folgt dem bewährten
`ticketing_knowledgebase_bridge`-Muster.

## 13. Verweise

- Decision 153 (Main-Module dürfen Contracts bereitstellen), 181 (Extension-Contracts),
  183 (Konnektor = Blattknoten ohne Contracts), 185 (Mandantentrennung); Kap. 26.4.2, 23.5.x.
- Referenz-Konnektor: `core/modules/ticketing_knowledgebase_bridge`.
- Core: `core/src/Service/Ai/AiGateway.php`, `EmbeddingService.php`,
  `core/src/Service/Search/SearchService.php`, `ContractRegistry.php`, `CapabilityHandle.php`,
  `EgressClient.php`. KB: `knowledgebase/src/Ai/AiAssistService.php`, `src/Web/Admin/AiStatusPage.php`.
- Vorgeschichte: Kap. 23.16 (In-Process + Capability-Gate, Entscheidung 187), `LICENSING.md` (AGPL §7).
