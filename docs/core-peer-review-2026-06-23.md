# Core Peer-Review — 2026-06-23

Multi-Agent-Peer-Review über den **gesamten** Fertura-Core (265 Quelldateien / ~33k LOC, 17 Subsysteme).
Methode: pro Subsystem ein tiefer Reviewer über alle Dimensionen (Mandanten-/RLS-Isolation, AuthN/SSO,
AuthZ/IDOR, Injection inkl. SSRF/zip-slip, Krypto/Secrets, CSRF/XSS, Atomicität/Races, Fail-open, DoS) →
**jeder Befund einzeln adversarial gegen den echten Code widerlegt** (Default „refuted" bei Unklarheit).

**Ergebnis:** 30 Rohbefunde → **22 bestätigt** (3 critical · 5 high · 9 medium · 5 low) · 2 unsicher · 6 widerlegt.

> Stand `main` = `07b4eb4`. Befunde gegen diesen Stand. Alles betrifft **Core-Code** (kein Modulcode).

## ✅ Remediation abgeschlossen (alle 22 behoben)

Alle bestätigten Befunde wurden behoben — jeweils mit Regressionstest + adversarialem Re-Review (0 Restbefunde) + grüner Voll-Suite, in 8 Phasen committet & gepusht:

| Phase | Befunde | Commit |
|---|---|---|
| A — SCIM/Token-Scoping | F2, F3, F5, F6 | `7a44a22` |
| B — SSO-Tenant-Bindung | F1, F4, F9, F10 | `359afd6` |
| C — Operator-Gating/Info-Disclosure | F14, F16, F17 | `0989212` |
| D — Marketplace key_type=root | F7 | `a59721e` |
| E — Language-Pack Path-Traversal | F8 | `86d6b0d` |
| F — Audit-Export RLS-Kontext | F11, F15 | `48995ef` |
| G — Fail-open-Härtung | F12, F13, F20 | `30e6987` |
| H — Low-Befunde | F18, F19, F21, F22 | `069205a` |

Endstand: **631 Tests grün**, PHPStan + PHPCS sauber, ein phpstan-Baseline-Eintrag entfernt (Netto-Verbesserung).

Die zwei optionalen Punkte (nicht Teil der 22) wurden **ebenfalls bearbeitet** (`119850f`): **U2** — Gemini-API-Key wandert in den `x-goog-api-key`-Header (kein Leak via URL/Logs); **U1** — `DbQueueTransport`-Contract explizit gemacht (job_queue ist operator-globaler Broker, bewusst ohne tenant_id/RLS; nie tenant-scoped Daten einreihen — RLS würde den kontextlosen Worker blocken; für künftigen tenant-scoped Bedarf zuerst tenant_id+RLS nachrüsten).

---

## Systemische Themen (Wurzelursachen)

1. **`core.users` hat bewusst KEINE RLS-Policy** (damit der Pre-Auth-Login Benutzer lesen kann; E-Mail/Username sind GLOBAL unique).
   Folge: **jede** Query gegen `users` MUSS einen expliziten Tenant-Prädikat (`tenant_id = core.current_tenant()`) tragen — mehrere Pfade tun das nicht. Das ist die Wurzel von **F1–F6, F16, F17** (SSO-Login, SCIM, Dashboard, Nav-Tiles).
   `users.tenant_id` hat zudem als DEFAULT den **Operator-Tenant** (`…0001`) als Literal (nicht `core.current_tenant()`), wodurch Inserts ohne explizites `tenant_id` falsch im Operator-Tenant landen.
2. **RLS-Kontext geht bei gestreamten Responses verloren.** `SET LOCAL app.current_tenant_id` wird von `TransactionRlsMiddleware` beim Request-Commit verworfen; ein `CallbackStream`-Body wird *nach* dem Commit emittiert → `core.current_tenant()` ist NULL → Audit-Exports liefern fail-closed **leer** (F11, F15).
3. **Fail-open in Sicherheits-/Recovery-Pfaden** statt fail-closed: TOTP-Replay (Cache), MFA-Pflicht (Settings), Webhook-Reclaim (RLS ohne Tenant-Kontext auf NOBYPASSRLS) — F12, F13, F20.
4. **Operator-/Mandanten-Trennung lückenhaft an gate-freien Admin-Seiten** (Dashboard, Nav, `scim:manage`-Token) — F5, F16, F17.

---

## CRITICAL

### F1 — SSO-Account-Linking ist mandanten-blind → Cross-Tenant-Account-Takeover
`core/src/Service/Auth/Sso/SsoService.php:161-232` · *tenancy-isolation*
`loginExternalUser()` löst die vom IdP behauptete Identität rein über eine **globale** E-Mail-Suche (`WHERE lower(email)=lower(:e)`) und globale `identity_links` auf und vergleicht **nie** `sso_providers.tenant_id` mit dem Tenant des getroffenen/erzeugten Users. Da jeder Mandant seinen eigenen IdP konfiguriert (er kontrolliert die Assertions), kann Mandant A seinen IdP `email=opfer@mandantB`, `email_verified=true` behaupten lassen; der `password_hash IS NULL`-Gate (passwortlose/eingeladene Nutzer) greift → A meldet sich **als** ein Mandant-B-Nutzer an.
**Fix:** Provider-`tenant_id` am Anfang laden; E-Mail-/Link-Auflösung **und** JIT-Provisionierung auf diesen Tenant scopen; bei E-Mail-Treffer in fremdem Tenant ablehnen (nicht durchfallen).

### F2 — SCIM `/Users` ohne Tenant-Scoping → vollständiges Cross-Tenant-Lesen/Enumerieren (IDOR)
`core/src/Controller/Api/Scim/ScimUsersController.php:44-94, 247-258` · *tenancy-isolation*
Alle Lese-Pfade (`index`/`view`/`find`) fragen `users` per Roh-SQL **ohne** `tenant_id`-Prädikat ab; da `users` keine RLS hat, ist der per-Request gesetzte Tenant-Kontext wirkungslos. Ein `scim:manage`-Token aus Mandant B liest/enumeriert **alle** Nutzer aller Mandanten inkl. Operator. (Reichweite für Tenant-Admins via F5.)
**Fix:** Caller-Tenant einmal auflösen, `AND tenant_id = :tenant` an `index`/`find`/Uniqueness/Updates; NULL fail-closed ablehnen.

### F3 — SCIM PUT/PATCH/DELETE mutieren/deaktivieren Nutzer über Mandantengrenzen
`core/src/Controller/Api/Scim/ScimUsersController.php:134-223, 260-278` · *tenancy-isolation*
`replace()/patch()/delete()/setActive()` finden das Ziel nur per UUID (`find()`, kein Tenant-Filter) und führen `UPDATE users … WHERE id=:id` ohne Tenant-Prädikat aus → Cross-Tenant-Umbenennen/E-Mail-Ändern/Deaktivieren beliebiger (auch Operator-)Konten = Takeover/DoS.
**Fix:** `AND tenant_id = core.current_tenant()` an `find()` und alle UPDATEs; fremde Rows → 404 statt Mutation.

---

## HIGH

### F4 — SSO-JIT-Provisionierung erzeugt Nutzer immer im Default-/Operator-Tenant
`core/src/Service/Auth/Sso/SsoService.php:187-191` · *tenancy-isolation*
INSERT ohne `tenant_id` → DB-Default = Operator-Tenant. Ein über Mandant-A-SSO angelegter Nutzer bekommt beim nächsten Request Operator-Tenant-RLS-Scope.
**Fix:** Provider-`tenant_id` explizit in den INSERT geben; nie auf den Spalten-Default verlassen.

### F5 — `scim:manage` ist von jedem Tenant-Admin selbst ausstellbar (kein Operator-Gate)
`core/src/Controller/Admin/TokensController.php:16, 28, 35-57` · *authz*
`requiredArea=null`, `requiresOperator=false` → jeder Admin erreichbar; `create()` akzeptiert beliebige Scopes ohne Allow-List; `scim:manage` ∈ `KNOWN_SCOPES`. **Das ist der Enabler, der F2/F3 für Tenant-Admins (statt nur Operator) ausnutzbar macht.**
**Fix:** Privilegierte/plattformweite Scopes (`scim:manage`) nur für Operator-Tenant — im Formular filtern **und** serverseitig in `create()` re-validieren.

### F6 — SCIM POST `/Users` ignoriert Caller-Tenant → alle neuen Nutzer im Operator-Tenant
`core/src/Controller/Api/Scim/ScimUsersController.php:108-131` · *tenancy-isolation*
`add()` INSERT ohne `tenant_id` → Operator-Tenant, unabhängig vom Token-Tenant; Dubletten-Check ebenfalls ungescopt.
**Fix:** Caller-Tenant explizit einfügen; Dubletten-Check auf den Tenant scopen.

### F7 — Marketplace-CRL/Anchor-Dokumente von BELIEBIGEM Trust-Anchor akzeptiert
`core/src/Service/Marketplace/MarketplaceClient.php:81-105, 127-154` · *crypto*
`verifySigned()` prüft Signaturen gegen jeden aktiven Anchor **ohne** `key_type='root'` zu verlangen. Da Publisher-Keys an Dritt-Vendoren verteilt sind, kann ein Publisher-Key eine gültige `anchors.json`/`crl.json` erzeugen → (1) `type:'root'`-Eintrag umgeht `verifyPublisherCert` (nur für `type==='publisher'`) → `addAnchor()` installiert einen **attacker-kontrollierten Root** mit unbegrenzter Gültigkeit → kompromittiert die Code-Signing-Kette; (2) Publisher-signierte CRL revoziert beliebige Keys inkl. echtem Root (Trust-DoS).
**Fix:** In `verifySigned()` Signer auf `key_type='root'` zwingen; `type:'root'`-Anchors nie aus geholten Dokumenten hinzufügen (Root wird out-of-band gebootstrappt); `verifyPublisherCert()` für jeden Anchor laufen lassen.

### F8 — Path-Traversal im Language-Pack-Store (beliebiges Datei-Schreiben/Löschen)
`core/src/Service/I18n/LanguagePackStore.php:40-48, 65-95, 97-108, 161-188` · *path-traversal*
`dir()/filePath()` konkatenieren `componentKey/version/domain` ungeprüft (kein `..`/`/`-Filter, kein realpath-Confinement). Aus `LocalizationController::import()` fließen diese Felder ungeprüft (nur `locale` regex-validiert) → `component=../../../../webroot/x` schreibt eine `.po` außerhalb der Sandbox (`atomicWrite` mkdir+write); `deletePack` gibt ein beliebiges `.po(.tmp)`-Lösch-Primitiv. Operator-Admin-gated + CSRF → authentifizierter Sandbox-Escape (kein Remote-RCE).
**Fix:** Komponenten strikt allow-listen (`/^[a-z][a-z0-9_]*$/`), Version gegen SemVer, `..`/`/`/`\` ablehnen — zentral in `dir()/filePath()` **und** am Controller-Rand; optional realpath-Confinement unter `base`.

---

## MEDIUM

### F9 — `SsoService::provider()/setActive()/deleteProvider()` nicht tenant-gescopt (IDOR auf SSO-Config)
`core/src/Service/Auth/Sso/SsoService.php:87-155` · *authz* — direkte-per-id-Accessor ohne Tenant-Filter (Listings filtern korrekt). Admin-Pfad aktuell operator-gated (defense-in-depth), aber `provider()` ist pre-auth im Login-Flow erreichbar. **Fix:** `AND tenant_id = coalesce(core.current_tenant(), <default>)` an alle drei.

### F10 — `SsoService::provider()` lädt fremde SSO-Config per Roh-id (pre-auth)
`core/src/Service/Auth/Sso/SsoService.php:87-108` · *tenancy-isolation* — `/sso/start/{providerId}` (unauth) reicht eine fremde Provider-UUID durch → Login-Flow läuft gegen fremde IdP-Config (Secret bleibt serverseitig, daher medius). **Fix:** wie F9; idealerweise tenant-RLS-Policy mit pre-auth-sicherem `coalesce(current_tenant, default)`.

### F11 — Audit-NDJSON-Export (API) streamt nach dem RLS-Commit → leer
`core/src/Controller/Api/V1/AuditController.php:35-41` · *fail-closed* — `CallbackStream`-Generator läuft post-commit; `core.current_tenant()` NULL → Policy matcht 0 Rows. Fail-closed, aber SIEM-Pull funktionslos. **Fix:** im Request-Tx materialisieren oder Tenant explizit in den Export geben.

### F12 — TOTP-Replay-Schutz failt open bei Cache-Ausfall/Eviction
`core/src/Service/Security/MfaService.php:145-154` · *fail-open* — „last step" nur in `CacheStore` (best-effort, no-op bei Fehler). Bei Cache-Ausfall ist ein abgefangener Code im ±1-Fenster wiederverwendbar. **Fix:** letzten Step durdauerhaft persistieren (z. B. `users.totp_last_step`), bei Nicht-Lesbarkeit fail-closed.

### F13 — Webhook-Stuck-Recovery no-opt in Produktion (RLS-Tabelle ohne Tenant-Kontext)
`core/src/Service/Webhook/WebhookService.php:122-132` · *fail-open* — `reclaimStuckDeliveries()` UPDATE auf RLS-`webhook_deliveries` ohne Tenant-Kontext/Bypass auf der NOBYPASSRLS-Default-Connection → 0 Rows. Crash-hängende Deliveries werden nie zurückgesetzt; Quiesce erreicht nie in-flight=0. **Fix:** `Db::privileged()` (BYPASSRLS) oder per-Tenant via `TenantIterator`.

### F14 — `/health/detail` und `/metrics` exponieren Infra-Detail an jeden authentifizierten Nutzer
`core/src/Controller/HealthController.php:61-76` (+ `MetricsController.php:41-56`) · *authz* — Gate ist nur `identity !== null`. Jeder Nutzer jedes Mandanten sieht DB-Rolle/bypass_rls, Storage-Pfad, Backup-Flags, vollständiges Modul-Inventar, Lizenz-/Registry-Counts und (bei Subsystem-Fehler) rohe Exception-Texte. **Fix:** Operator/Admin-Autorisierung verlangen; rohe Exceptions aus dem Payload entfernen (loggen, generisches „down").

### F15 — Audit-Export-NDJSON (Admin) post-commit → leer
`core/src/Controller/Admin/AuditController.php:88-92` (Service `AuditExportService.php:40-87`) · *tenancy-isolation* — gleicher Mechanismus wie F11 auf dem Admin-Export. **Fix:** wie F11.

### F16 — Admin-Dashboard leakt plattformweite/Cross-Tenant-Operator-Daten an Tenant-Admins
`src/Controller/Admin/DashboardController.php:13-39` · *tenancy-isolation* — `/admin`-Landing ohne `requiresOperator`; `index()` zählt ungescopt über `users` (keine RLS), `modules`, `licenses`, `contracts`, `event_outbox`. **Fix:** `requiresOperator=true` (wie `HealthController`) oder nur tenant-gescopte Zahlen für Nicht-Operatoren.

### F17 — Admin-Nav-Tile-Metriken leaken Cross-Tenant-Aggregate
`src/Controller/Admin/NavController.php:85-119` · *tenancy-isolation* — `tileMetrics()` `SELECT status,count(*) FROM users GROUP BY status` (alle Mandanten) → das `/admin/users`-Tile zeigt mandantenübergreifende Counts. **Fix:** User-Metrik per `tenant_id = core.current_tenant()` scopen; Operator-Domain-Tiles nur für Operator berechnen.

---

## LOW

- **F18** — Backup-Tar-Extraktion prüft keine Symlink/Hardlink/Device-Entries (zip-slip via Symlink), nur Namen. `core/src/Service/Backup/BackupService.php:629-647, 728-730`. Nur CLI-Restore eines manipulierten Archivs durch den Operator → low. **Fix:** Entry-Typen prüfen (`tar tzvf`), nur reguläre Dateien/Verzeichnisse; Staging-Dir + Copy.
- **F19** — Per-Tenant-Export lädt ganze Tabellen + Archiv in den Speicher (unbounded/DoS). `core/src/Service/Backup/TenantBackupService.php:196-204, 619-652`. Self-DoS des eigenen FPM-Workers. **Fix:** Zeilen gebatcht streamen (Keyset/Cursor), Restore zeilenweise lesen.
- **F20** — `MfaService::required()` failt open bei Settings-Fehler. `core/src/Service/Security/MfaService.php:68-75`. Betrifft nur das Erzwingen der MFA-*Einrichtung* (nicht den 2. Faktor enrollter Nutzer). **Fix:** fail-closed bzw. mindestens loggen.
- **F21** — Dollar-Quote-Breakout in App-Rollen-Provisioning. `src/Command/DbProvisionAppRoleCommand.php:50-58`. Passwort nur single-quote-escaped, in `DO $do$…$do$` interpoliert → `$do$` im Passwort bricht den Block (operator-kontrollierte Env, daher low). **Fix:** Passwort nicht in DO-Block interpolieren; parametrisiertes `ALTER ROLE … PASSWORD :pw` bzw. zufälliges, geprüftes Dollar-Tag.
- **F22** — `salutation` mass-assignable ohne Validierung (unbounded `text`). `core/src/Model/Table/UsersTable.php:41-66`. **Fix:** `scalar()->maxLength(100)` wie `first_name/last_name` (ggf. `inList`).

---

## Unsicher (zur Sichtung, kein bestätigter Trigger)

- **U1** — Generische Job-Queue (`DbQueueTransport`/`RedisStreamTransport`) ohne Tenant-Isolation (keine `tenant_id`/RLS). **Latent**: aktuell kein Producer/Consumer in Produktion (`OutboxWorker` ruft nur `reclaimStuck()`/`size()`). Forward-Hardening: vor dem ersten tenant-scoped Producer `tenant_id`+RLS nachrüsten.
- **U2** — Google/Gemini-Provider sendet den (operator-globalen) API-Key im URL-Query statt Header. Best-Practice-Härtung; der konkrete Leak-Pfad (Exception/Proxy-Log) ließ sich nicht gegen echten Code belegen. **Fix:** `x-goog-api-key`-Header (wie OpenAI/Anthropic).

## Widerlegt (6)
6 Rohbefunde wurden vom Verifier gegen den echten Code als False-Positive verworfen (bereits mitigiert / nicht attacker-kontrolliert / intendiertes CLI-Verhalten).

## Abdeckung (bestätigt je Subsystem)
tenancy-rls 3 · api-controllers 5 · backup-storage 2 · security-services 2 · privacy-audit-search 2 · admin-web-controllers 2 · auth-sessions 1 · system-update-deploy 1 · async-webhook 1 · settings-notif-mail 1 · commands 1 · models-orm 1 · authz-permissions 0 · api-middleware 0 · module-system 0 · registry-contracts 0 · ai-providers 0.

---

## Empfohlene Remediationsreihenfolge
1. **Sofort (critical + der Enabler F5):** F5 (scim:manage Operator-Gate) + F2/F3 (SCIM Tenant-Scoping) + F1 (SSO tenant-blindes Linking). Diese vier schließen den ausnutzbaren Cross-Tenant-Auth-/Directory-Pfad.
2. **Kurzfristig (high):** F4/F6 (SSO/SCIM Tenant-Attribution), F7 (Marketplace key_type='root'), F8 (Language-Pack Path-Sanitisierung).
3. **Danach (medium):** F9/F10 (SSO provider scoping), F11/F15 (Audit-Export post-commit), F12/F13 (fail-open), F14/F16/F17 (Operator-Gate/Info-Disclosure).
4. **Aufräumen (low):** F18–F22.

Querschnitt-Fix mit größtem Hebel: ein **zentraler tenant-gescopter User-Accessor** (statt verstreuter Roh-SQL gegen das RLS-freie `users`) + `users.tenant_id`-DEFAULT auf `core.current_tenant()` statt Operator-Literal.
