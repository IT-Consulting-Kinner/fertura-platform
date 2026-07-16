# Betreiber- vs. Mandanten-Admin-Trennung — Design

Status: **Entwurf zur Freigabe** (Design-First; noch kein Code). Sicherheits-/Autz-kritisch
→ jeder Increment wird adversarial reviewed; der bestehende Cross-Tenant-User-Leak (siehe §1)
wird früh und zuerst geschlossen.

## 1. Problem / Ist-Zustand

Das Produkt ist jetzt ein Multi-Tenant-SaaS (Norm 185), aber das Admin-/Autorisierungsmodell
kennt **keine Betreiber-/Mandanten-Achse**. Konkret (evidenzbasiert):

- **Keine Operator/Mandant-Rolle.** Jeder Admin ist „ein User mit ein paar `admin_areas`". Es gibt
  kein `is_operator`, keine Operator-Area — der „Operator" existiert nur als Kommentar + Konvention
  (Default-Tenant `00000000-0000-0000-0000-000000000001`).
- **`admin_areas` + `user_admin_areas` sind global** (keine `tenant_id`, keine RLS). Ein Area-Grant
  gilt plattformweit.
- **Cross-Tenant-Leak:** `UsersController` listet `SELECT … FROM users` **ohne** Tenant-Filter, und
  `users` hat bewusst **keine RLS** (Pre-Auth-Ausnahme). Ein `user_group_admin`-Admin sieht **alle
  User aller Mandanten**. (`GroupsController` filtert als einziger manuell per `tenant_id`.)
- **`core_config` vermischt** Betreiber-Belange (Mandanten verwalten, System-Backup, Trust,
  Integrationen) mit mandanteninternen — nicht delegierbar.
- **Module sind global** (kein Per-Tenant-Enable/-Config); Lifecycle/Updates/Sprachpakete/Maintenance
  sind plattformweit, nur per Area gegated, ohne Operator-Erzwingung.
- **Zwei Autz-Schichten nebeneinander:** `admin_areas` (gaten Admin-Seiten) und `PermissionService`
  BREAD (gaten Modul-Daten) — ohne Bezug zueinander.

## 2. Zielmodell — fixierte Entscheidungen

1. **Operator = Operator-Mandant.** Der Default-Tenant ist der Operator-Mandant; **Operator-Admins
   sind seine User**. „Operator-sein" folgt aus der Tenant-Zugehörigkeit, kein zusätzliches Flag. **JA**
2. **Mandanten-Admin = nur eigener Tenant** (`users.tenant_id`). Kein Cross-Tenant-Admin; Cross-Tenant
   macht ausschließlich der Operator. → Tenant-Area-Grants brauchen **kein** `tenant_id` (implizit auf
   den eigenen Tenant scoped). **JA**
3. **Modul-Modell:** **Lifecycle = Operator** (install/update/remove, plattformweit), **Enable +
   Config = Mandant** (pro Tenant). **JA** (neu — heute global)
4. **Area-Split** in Operator- vs. Tenant-Areas; `core_config` aufgebrochen; Nav in „Betreiber" vs.
   „Mandant". **JA**
5. **Operator-Benutzerverwaltung nur für Operator-Admins** (User des Operator-Mandanten), **nicht** für
   Mandanten-User. Mandanten-User verwaltet der jeweilige Mandanten-Admin. **JA**
6. **Tenant-Daten-Backup/Restore = Mandanten-Admin** (Per-Tenant-Feature, separat designt);
   **System-Backup/Restore = Operator**; **DR / Cross-Tenant-Voll-Restore = CLI-only**. **JA**

## 3. Autorisierungsmodell

### 3.1 Diskriminator
`OPERATOR_TENANT_ID := DEFAULT_TENANT_ID`. Ein User ist **Operator-Admin**, wenn
`tenant_id = OPERATOR_TENANT_ID`; sonst **Mandanten-Admin** seines Tenants. (Single-Org heute: alle
User liegen im Default-Tenant → alle sind Operator-Admins → rückwärtskompatibel, §7.)

### 3.2 Area-Typisierung
Jede Area bekommt einen **`scope ∈ {operator, tenant}`** (neue Spalte auf `core.admin_areas`, plus
Code-Klassifikation in `AdminController::NAV` für die Core-Areas; modul-beigesteuerte Areas sind per
Default `tenant`, da Modul-Admin-Seiten Mandanten-Konfiguration sind).

### 3.3 Enforcement (zwei Tore, beide serverseitig)
- **Operator-Area:** Zugriff nur wenn `user.tenant_id = OPERATOR_TENANT_ID` **und** der User die Area
  hält. Defense-in-depth: ein an einen Mandanten-User vergebener Operator-Grant ist **wirkungslos**.
- **Tenant-Area:** Zugriff wenn der User die Area hält; **alle Daten-Queries sind auf
  `tenant_id = current_tenant()` gescoped** (der Controller, nicht nur RLS — siehe §3.4).
- Erweiterung von `AdminController::beforeFilter()`/`loadUserAreas()`: die Area-Prüfung berücksichtigt
  den `scope` und die Operator-Mandant-Zugehörigkeit.

### 3.4 Per-Controller-Tenant-Scoping (schließt die Leaks)
Tenant-Area-Controller MÜSSEN explizit nach `tenant_id = current_tenant()` filtern (RLS allein reicht
nicht, da `users` keine RLS hat). Mindestens:
- `UsersController` (**der akute Leak**): `… WHERE tenant_id = current_tenant()` — für Mandanten-Admins;
  der Operator-Pendant verwaltet nur Operator-Mandant-User (§4).
- `GroupsController`: filtert bereits — als Muster übernehmen.
- Operator-Area-Controller (`TenantsController`, `Updates`, `Modules`-Lifecycle, `Backup`,
  `Maintenance`, `Localization`, Trust) laufen bewusst plattformweit (privileged) und sind durch das
  Operator-Tor gedeckt.

### 3.5 Verhältnis `admin_areas` ↔ `PermissionService`
Bleiben zwei Schichten: **Areas** gaten Admin-Seiten (grobgranular, Operator/Tenant), **BREAD/
`PermissionService`** gaten Modul-Ressourcen (feingranular, bereits tenant-scoped via RLS auf
`groups`/`group_resource_permissions`). Keine Verschmelzung; die Area-Schicht bekommt nur die
Operator/Tenant-Achse, die BREAD-Schicht ist schon tenant-isoliert.

## 4. Area-Taxonomie (Vorschlag)

**Operator-Areas** (nur Operator-Mandant):
- `op_tenants` — Mandanten anlegen/suspendieren/löschen (heute Teil von `core_config`)
- `op_modules` — Modul-**Lifecycle** (install/activate/deactivate/delete) — aus `module_lifecycle`
- `op_updates` — Core-/Modul-Updates (aus `update_manager`)
- `op_marketplace` — Marketplace/Lizenzen (aus `marketplace_license`)
- `op_registry` — Registry/Contracts (aus `registry_contracts`)
- `op_localization` — Sprachpakete (aus `localization`)
- `op_system` — System-Backup/Restore, Trust, plattformweite Integrationen/Settings (Rest von
  `core_config`)
- `op_maintenance` — Wartungsmodus (aus `system_maintenance`)
- `op_admins` — **Operator-Admins verwalten** (User des Operator-Mandanten + deren Operator-Grants)

**Tenant-Areas** (eigener Mandant, implizit gescoped):
- `tenant_users` — Mandanten-User + Gruppen (der tenant-scoped Teil von `user_group_admin`)
- `tenant_modules` — Module **aktivieren + konfigurieren** pro Mandant
- `tenant_backup` — **Tenant-Daten-Backup/Restore** (Per-Tenant-Feature)
- `tenant_settings` — mandantenspezifische Einstellungen/Branding

`SYSTEM` (health/audit/tokens, heute gate-frei) wird aufgeteilt: Operator sieht plattformweite Health/
Audit; Mandanten-Admin sieht den auf seinen Tenant gescopeten Audit-Ausschnitt (Audit ist tenant-
markiert).

> Hinweis: die exakten Keys sind Vorschlag; entscheidend ist die **Achse** (operator vs tenant) und
> dass `user_group_admin`/`core_config` zerlegt werden.

## 5. Modul-Modell: Lifecycle (Operator) vs. Per-Tenant (Mandant)

Neu: **Per-Tenant-Modul-Aktivierung + -Konfiguration.** Heute ist ein Modul global „active". Künftig:
- **Lifecycle (Operator):** ein Modul wird einmal plattformweit installiert/aktualisiert/entfernt
  (`modules`-Tabelle, `op_modules`). „Installiert + plattform-aktiv" = verfügbar.
- **Per-Tenant-Enable + Config (Mandant):** eine neue Tabelle `core.tenant_modules`
  (`tenant_id, module_key, enabled, config jsonb`, RLS-tenant-scoped). Ein Mandanten-Admin schaltet ein
  verfügbares Modul für **seinen** Tenant ein und konfiguriert es; die Modul-Web-Mounts/Nav/Listener
  greifen nur für Tenants, die es aktiviert haben.
- **Operator-Mandant nutzt keine Module** (D5): für ihn ist `tenant_modules` leer — er sieht nur
  Operator-Areas + `op_admins`.

Dies ist ein **eigener, substanzieller Increment** (berührt Web-Mount-Dispatch, Nav, Listener-
Aktivierung) und der Core/Modul-Contract (Module liefern ihren Config-Vertrag).

### 5a. Betriebsverhalten der Bereitstellung/Aktivierung (Stand 2026-07-03, entschieden)

Zwei Ebenen, keine dritte:
- **Betreiber „stellt bereit" = plattformweite Aktivierung** (`modules.status='active'`, operator-only
  `ModulesController`). Damit ist das Modul für **alle** Mandanten *verfügbar*.
- **Kundentenant-Admin „aktiviert + nutzt"** über `Mandant ▸ Module` (`TenantModulesController`,
  tenant-scoped): er schaltet ein aktives Modul für **seinen** Tenant ein. `TenantModuleService`
  ist strict opt-in / fail-closed (`isEnabled` / `ContractRegistry::gateByTenantModules`) — Web-Mounts,
  Nav und **Event-Listener** greifen nur für Tenants mit einer `tenant_modules(enabled=true)`-Zeile.

**Bewusste Entscheidung (2026-07-03): KEINE per-Kundentenant-Bereitstellungs-Ebene.** Jedes
plattform-aktive Modul ist für **jeden** Tenant freischaltbar; der Betreiber steuert nicht pro
Kundentenant, *welche* Module ein einzelner Tenant sehen/aktivieren darf (Tarif-/Vertrags-Gating ist
bewusst nicht abgebildet). `TenantModuleService::listForTenant` zeigt jedem Tenant-Admin per LEFT JOIN
**alle** aktiven Module — ein **neu angelegter** Mandant „erbt" damit automatisch die Sicht auf alle
bereitgestellten (aktiven) Module und kann sie sofort aktivieren.

**Konsequenzen (per Design, KEIN Bug):**
- Ein **neu angelegter** Mandant startet mit **null aktivierten** Modulen — er *sieht* alle aktiven,
  muss sie aber selbst aktivieren (Tenant-Opt-in). `TenantService::create()` schreibt bewusst keine
  `tenant_modules`-Zeilen.
- Ein **neu plattform-aktiviertes** Modul (z. B. die AI-Topologie ki_dienste + Bridges) ist sofort für
  alle Tenants *aktivierbar*, aber pro Tenant **inert**, bis dessen Admin es aktiviert. Ein
  integritätswahrender Konnektor (z. B. die Ticketing↔KB-Bridge) pflegt Referenzen erst ab Aktivierung
  durch den Tenant.
- Der einmalige Backfill in `CoreTenantModules` (2026-06-22) hat *damals* aktive Module × *damals*
  existierende Tenants enabled (Upgrade-Kompatibilität); danach ist Aktivierung ausschließlich
  Tenant-Aktion. Die Whole-Stack-Harness bildete das anfangs nicht ab (Test-Tenant ohne Enablement) →
  im Harness-Test behoben, nicht im Core.

### 5b. Betreiber-Tenant ist modul-frei im MultiOrg-Modus (Stand 2026-07-08, umgesetzt — Option B)

**Bindende Anforderung:** Ein Admin im **Betreiber-Tenant** führt **ausschließlich Betreiber-Funktionen**
aus; aktivierte Module und die damit verbundenen Funktionen sind **nur in den Kundentenants** verfügbar
(und dort keine Betreiber-Funktionen). Die Gegenrichtung (Kundentenant → Betreiber-Funktion) war bereits
durch das Operator-Tor (§1) erzwungen; **neu** ist die Richtung Betreiber-Tenant → **keine** Modul-Funktion.

**Explizites App-Setting `core.tenancy.mode` steuert das Verhalten** (nicht der Tenant-Count):
- `single_org` (**Default**, rückwärtskompatibel): der Default-Tenant behält seine historische
  **Doppelrolle** (Betreiber **und** einziger Modul-Nutzer). Keine Trennung. Decision 185
  „Single-Org = Default-Tenant, kein zweiter Code-Pfad" bleibt intakt.
- `multi_org`: **strikte Trennung** — der Betreiber-/Default-Tenant ist **modul-frei**, Module leben nur in
  Kundentenants.

Das Setting ist im `SettingsCatalog` deklariert (`['type'=>'string','default'=>'single_org','allowed'=>['single_org','multi_org']]`);
der neue generische `allowed`-Allow-List-Check in `SettingsCatalog::validate()` erzwingt die zwei Werte auf
beiden Schreibpfaden (`SettingsManager::set` + `ConfigController::save`), und die Config-GUI rendert ein
Dropdown (Template-else-if für `allowed`-Settings). Es ist ein **globales** (Operator-)Setting
(`tenant_id NULL`), editierbar im `core_config`-Bereich.

**Single Source of Truth — DB-Prädikat** (Migration `CoreTenantModuleFreeFunction`):
```sql
core.tenant_is_module_free(t uuid) :=
    t = <default>
    AND coalesce((SELECT s.value #>> '{}' FROM core.settings s
                  WHERE s.namespace='core' AND s.config_key='tenancy.mode' AND s.tenant_id IS NULL),
                 'single_org') = 'multi_org'
```
`core.settings` trägt keine RLS → die globale Zeile ist aus **jedem** Tenant-Kontext lesbar; das explizite
`tenant_id IS NULL` liest tenant-neutral, sodass ein etwaiger Per-Tenant-Override den Modus nicht kippen
kann. Fehlende Zeile → `coalesce` → `single_org` (Katalog-Default) → nicht modul-frei (**false**, nicht
NULL). Ein NULL-Tenant-Argument bleibt falsy → jeder Aufrufer behält seine NULL-/no-tenant-Semantik.

**Enforcement-Punkte (alle konsumieren dasselbe Prädikat — unverändert gegenüber der Count-Fassung):**
- `TenantModuleService::isEnabled / enabledKeys / enabledModules` — `… AND NOT core.tenant_is_module_free(…)`:
  Web-/API-Dispatch, Nav-Filterung und Consumption liefern für den Betreiber-Tenant (multi_org) nichts.
- `ContractRegistry::gateByTenantModules` — droppt für den modul-freien Betreiber-Tenant **alle**
  Modul-Contributions (Listener/Collectors/Provider); Core/Plattform-Contributions passieren weiter.
- `TenantModuleService::enable` (+ `TenantModulesController`-GUI, i18n-Flash) — Enable im Betreiber-Tenant
  wird abgewiesen (Service-Defense deckt auch Provisioning-Pfade).
- **Nav** (`AdminNavBuilder::menu($areas, $isOperatorTenant)`): ein Kundentenant-Viewer sieht das
  Betreiber-Realm nicht (nur die immer verfügbaren System-Seiten). Die Modul-Areas des Betreiber-Tenants
  verschwinden ohnehin über das (nun modus-getriebene) Enablement-Gate; der Operator-Viewer braucht daher
  keine zusätzliche Nav-Filterung.

**Nicht-destruktiv / reversibel:** rein lesende Gatterung — bestehende `tenant_modules`-Zeilen des
Betreiber-Tenants (z. B. aus dem `CoreTenantModules`-Backfill oder Single-Org-Nutzung) werden **nicht**
gelöscht, sondern nur inert, solange `multi_org` gilt. Zurückstellen auf `single_org` reaktiviert die
Doppelrolle sofort.

**Bekannte, orthogonale Altlast (kein Bug dieser Änderung):** die Tenant-Consumer-Areas
`tenant_modules`/`consumption`/`tenant_backup` stehen in `AdminController::NAV` + `TENANT_AREAS`, aber nicht
in `AdminNavBuilder::ADMIN_ORDER` → sie erscheinen aktuell in keinem Top-Menü-Dropdown (nur per Direkt-URL
erreichbar). Das ist eine **bestehende** Nav-Verdrahtungslücke und wird hier bewusst nicht mitbehoben.

## 6. Navigation

Top-Menü wird zwei **Realms**:
- **„Betreiber"** — nur für Operator-Admins sichtbar (Operator-Areas + plattformweites System).
- **„Mandant"** — Tenant-Areas + modul-beigesteuerte (aktivierte) Modul-Admin-Seiten.

Der Operator-Mandant zeigt nur „Betreiber". Ein Mandanten-Admin zeigt nur „Mandant". (Ein
Operator-Admin, der zugleich einen Mandanten betreuen soll, ist per D1/D2 ausgeschlossen — Cross-Tenant
läuft über die Operator-Funktionen, nicht über Mandanten-Admin-Sein.)

`AdminNavBuilder::menu()` wird entsprechend von „module/administration" auf „betreiber/mandant"
umgestellt; `AdminController::NAV` bekommt je Area den `scope`.

## 7. Migration / Rückwärtskompatibilität

- **Single-Org-Bestand:** alle User liegen im Default-(=Operator-)Tenant → alle werden Operator-Admins;
  ihre bestehenden Area-Grants werden auf die neuen Operator-Areas gemappt. **Kein Funktionsverlust.**
- **`CreateAdminCommand`** erzeugt künftig einen Operator-Admin (Default-Tenant) mit den Operator-Areas.
- **Area-Mapping-Migration:** `user_group_admin` → `tenant_users` (für Nicht-Operator-Tenants) bzw.
  `op_admins` (für Operator-User); `core_config` → `op_tenants` + `op_system`; `module_lifecycle` →
  `op_modules`; etc. Idempotent + revisionssicher.
- **Erster Mandanten-Admin:** beim Provisionieren eines Mandanten (Operator) wird optional ein
  initialer Mandanten-Admin-User angelegt/zugeordnet, der dann innerhalb seines Tenants weitere
  Mandanten-Admins/User verwaltet (`tenant_users`). *(Offen — siehe §10.)*

## 8. Increment-Plan

0. **Dieses Design-Doc** (Freigabe).
1. **Diskriminator + Area-Typisierung + Enforcement:** ✅ — `OPERATOR_TENANT_ID`, Area-Scope per
   Code-Klassifikation (`AdminController::TENANT_AREAS`; jede andere Core-Area = operator; Modul-Areas =
   tenant; **fail-closed** für Core), Operator-Tenant-Tor in `AdminController::beforeFilter`, plus
   `requiresOperator` für gate-freie Operator-Seiten. Adversarial reviewed → zwei Bypässe abgedichtet:
   `SearchController::reindex` (inline-Grant ohne Operator-Tor) und `/admin/health` (gate-frei →
   Plattform-Operator-Daten an Mandanten-Admins). Die `scope`-DB-Spalte ist bewusst auf Inc 3
   verschoben (Code ist die Quelle der Wahrheit für Core-Areas; die Spalte wird erst beim Nav-/Area-
   Umbau konsumiert).
2. **Cross-Tenant-Scoping / Leak-Fix (Sicherheit, vorgezogen):** ✅ — `UsersController` (ALLE Aktionen,
   inkl. setPassword-Takeover) + `GroupsController::addMember` (Review-HIGH) auf
   `tenant_id = core.current_tenant()`; Last-Admin-Schutz per-Tenant; `add()` fail-closed;
   Isolationstests, die den Leak nachweisen + schließen.
3. **Nav-Realms:** ✅ (Teil) — `AdminNavBuilder::menu()` gruppiert die gehaltenen Areas nach **Scope**
   in das Operator-Realm (Betreiber: operator-scoped Core-Areas + System) und das Tenant-Realm
   (Mandant: tenant-scoped Core-Area + modul-beigesteuerte Areas); `areaTop`/`computeActiveTop` folgen
   dem Scope; Labels via i18n (Betreiber/Mandant bzw. Operator/Tenant). Gruppierung **by Scope** (nicht
   by Viewer), weil in Single-Org der Default-Tenant zugleich Operator ist UND Module nutzt — ein
   Viewer kann legitim beide Realms halten. Interne Keys `module`/`administration` + Routen bleiben
   (transitional; Rename ist späterer Kosmetik-Cleanup). **Noch offen** (eigene Increments): die
   granularen Area-Key-Splits (`core_config`→`op_tenants`/`op_system`, `user_group_admin`→`op_admins`/
   `tenant_users`) + Mapping-Migration fallen mit Inc 4 / den Feature-Increments; der System-Seiten-
   Realm-Split (audit/tokens je Realm) bleibt §10.
4. **Onboarding-Schritt** ✅ — `TenantsController::createAdmin` (Operator-Aktion, core_config-gegated):
   legt einen User IM Ziel-Tenant an (cross-tenant, Operator-Privileg; `users`/`user_admin_areas`
   ohne RLS), vergibt `user_group_admin` + Einladungs-Link; der Operator-Mandant ist als Ziel
   ausgeschlossen. Tests: User landet im Ziel-Tenant + Grant + Invite; Operator-Tenant wird abgewiesen.
   **Noch offen** (Refinement, da die Grenze schon erzwungen ist): `op_admins` (eigener Operator-Admin-
   Verwaltungsbereich) + die granularen Area-Key-Splits (`user_group_admin`→`op_admins`/`tenant_users`,
   `core_config`→`op_tenants`/`op_system`) + `toggleArea`-Scoping (Tenant-Admin darf nur Tenant-Areas
   vergeben). E-Mail-Eindeutigkeit (per-Tenant vs. global) ist ein vorbestehender, davon unabhängiger
   Punkt.
5. **Per-Tenant-Modul-Enable + -Config** — Modell **strikt opt-in / fail-closed** (entschieden):
   ein Mandant nutzt ein installiertes Modul nur mit explizitem Grant.
   - **5.1 ✅** — `core.tenant_modules` (RLS tenant-scoped, FK-CASCADE auf tenants+modules,
     `UNIQUE(tenant_id,module_key)`, `config jsonb` für Phase 3) + Backward-Compat-Backfill
     (aktive Module × bestehende Mandanten, damit bestehende/Single-Org-Installs nichts verlieren);
     `TenantModuleService` (fail-closed `isEnabled`/`enabledKeys` mit explizitem
     `tenant_id = core.current_tenant()`; `enable`/`disable`); zwei **Web-Gates** —
     `ModuleWebController::dispatch` (authentifizierte Modul-Seite → 404 wenn nicht freigeschaltet;
     Guest-Seiten bleiben ungated) und `WebRouteRegistry::adminNav` (Modul-Nav nur für
     freigeschaltete Mandanten). Adversarial reviewed → ein **HIGH** abgedichtet: ein Web-Route mit
     `area` **und** `auth='guest'` umging den gesamten Auth-/Enablement-/Area-Block und rendete
     trotzdem in der Admin-Shell — jetzt behandelt der Dispatcher **jede** Admin-Seite (`area`
     gesetzt) als authentifiziert, und der `ManifestLinter` weist `area`+`auth!='user'` schon beim
     Install ab (Defense-in-Depth). Tests: `TenantModuleRlsTest` (Service + RLS-Leak-Proof via
     NOBYPASSRLS-Rolle), `ModuleWebTest` (+Negativ-Gate, Guest bleibt erreichbar), `ManifestLinterTest`.
   - **5.2 ✅** — API-Gate: `Api/V1/ModuleController::dispatch` prüft für `auth='user'`-Routen das
     Enablement (404 vor dem Scope-Check); `public`-Routen ausgenommen (kein Core-Auth/Tenant-Kontext).
     Tenant-Kontext gesichert (ApiAuthMiddleware → identity → TransactionRlsMiddleware); einziger
     Laufzeit-Dispatch-Eintrittspunkt verifiziert. Test in `ModuleWebTest`.
   - **5.3 ✅** — Mandanten-GUI: Tenant-Area `tenant_modules` (in `TENANT_AREAS` → kein Operator-Tor),
     `TenantModulesController` (`index`/`enable`/`disable`, current-tenant-scoped, `isActiveModule`-Guard,
     Audit), `TenantModuleService::listForTenant`, Template (UiKit, `confirmPost`), i18n de/en;
     `createAdmin` vergibt jetzt beide Tenant-Admin-Areas atomar. Adversarial reviewed → 2 Befunde:
     `confirmPost`-Escaping (False Positive — FormHelper escaped Attribute; Regressionstest gegen Doppel-
     Escape), `createAdmin`-Grant-Atomarität (Multi-Row-INSERT). Tests: `TenantModulesControllerTest`,
     `TenantsControllerTest`, `UiKitHelperTest`.
   - **Phase 2 ✅** — Contribution-Gating: `ContractRegistry::gateByTenantModules` verwirft Beiträge
     (Listener/Collector/Provider), deren `module_key` ein installiertes, für `core.current_tenant()`
     **nicht** freigeschaltetes Modul ist (ein indizierter Query in `activeContributions`/`resolveProvider`;
     `activeImplClasses` delegiert). **fail-OPEN bei NULL-Tenant** (System-Events/CLI/Plattform-Jobs laufen
     weiter; RLS schützt die Daten), **Core-exempt** (`module_key` nicht in `modules`). Greift automatisch im
     OutboxWorker (Event-Tenant gesetzt = Ziel), Notification (per-User), CapabilityHandle (Provider). Opt-out
     `tenantScoped=false` für Plattform-/Privacy-Consumer (Health, Search-Reindex, Anonymisierung).
     Test: `TenantModuleContributionGateTest`.
   - **Phase 3 ✅** — Per-Tenant-Config-Contract: ein Modul deklariert seine konfigurierbaren Felder
     deklarativ im Manifest (`config_schema`: key/label/type bool|int|string|text|select/default/help/
     options/required; `ModuleManifest::configSchema`, `ManifestLinter`-Validierung). Werte in
     `tenant_modules.config` (`TenantModuleService::config`/`setConfig`/`configSchema`; setConfig filtert
     auf Schema-Keys). **Runtime-Injection** als `module_config` in Web-/API-Request + Listener-Context
     (OutboxWorker, je Listener seine Modul-Config für den Event-Tenant) — Module lesen ihre Config ohne
     Core-Aufruf. **Mandanten-GUI** `TenantModulesController::configure` (Formular aus dem Schema,
     typ-coerct, Audit) + „Konfigurieren"-Link für freigeschaltete, konfigurierbare Module. Tests:
     `TenantModuleRlsTest`, `ManifestLinterTest`, `ModuleWebTest`, `TenantModulesControllerTest`.
   - **Damit ist Inc 5 vollständig:** Tabelle + Web/API-Gates + Enable/Disable-GUI + Contribution-Gating
     (Phase 2) + Per-Tenant-Config (Phase 3).
   - **Refinement ✅** — `OpenApiGenerator` ist jetzt per-Mandant: die Spec listet Modul-`user`-Routen nur
     für freigeschaltete Module (öffentliche Routen immer); `TenantModuleService` injizierbar; ein
     bestehender phpstan-Baseline-Eintrag (ApiRouteRegistry|null) entfiel dabei.
   - **Noch offen:** Der **Integration-Harness** (separates Repo) muss seine Modul-Tests anpassen — nach
     `ModuleLifecycle::activate($key)` das Modul pro Test-Mandant freischalten
     (`TenantModuleService::enable($tenantId, $key)`), sonst greifen die fail-closed Gates (Web/API/Nav/
     Listener) für tenant-scoped Zugriffe/Events. Korrektur-Prompt ist erstellt/übergeben.
6. **Tenant-Daten-Backup/Restore** (Mandant) + **System-Backup/Restore** (Operator) sauber getrennt;
   knüpft an das Per-Tenant-Backup-Design an.

Jeder Increment: phpstan + phpcs + Tests grün, adversarial review (Autz-kritisch).

## 9. Sicherheit

- Der **Users-Leak (§1)** ist eine bestehende Cross-Tenant-Datenexposition → Increment 2 zuerst, mit
  Test, der die Isolation erzwingt.
- **Fail-closed (Norm 185):** unbekannter/fehlender `scope` ⇒ Operator-Tor (restriktiver); ein
  Tenant-Area-Controller ohne expliziten Filter ⇒ Test schlägt fehl (Lint-/Test-Gate).
- Operator-Tor und Tenant-Scope sind **unabhängige** Prüfungen (defense-in-depth): selbst ein
  fehlgeleiteter Grant kann die jeweils andere Achse nicht überwinden.

## 10. Offene Punkte (für die Umsetzung zu klären)

- **Erst-Mandanten-Admin-Onboarding:** ✅ **entschieden — separater Schritt.** Das Provisioning legt
  nur den leeren Mandanten an; der erste Mandanten-Admin wird danach in einem eigenen Schritt erzeugt
  (Operator legt einen User im Ziel-Tenant an + vergibt die Tenant-Admin-Area + Einladungs-/Passwort-
  Reset-Link). Fällt in Inc 4 (`op_admins` / Operator-seitige Mandanten-Admin-Verwaltung).
- **Audit-Sichtbarkeit:** sieht ein Mandanten-Admin nur seinen Tenant-Audit-Ausschnitt? (Audit ist
  tenant-markiert; Vorschlag: ja, gescoped.)
- **`SYSTEM`-Gruppe (health/audit/tokens):** `/admin/health` ist seit Inc 1 operator-gegated
  (`requiresOperator` → Plattform-Operator-Daten nur für Betreiber); `audit_log` hat RLS
  (auto-tenant-gescoped, kein Leak); **API-Tokens** noch offen (vermutlich tenant-scoped). Die
  Nav-Sichtbarkeit der SYSTEM-Links je Realm folgt mit dem Nav-Umbau (Inc 3).
- **API-Tokens:** Operator- vs. Mandanten-Scope der Tokens (vermutlich tenant-scoped für Mandanten).
- **Modul-Config-Contract:** wie deklariert ein Modul seinen Per-Tenant-Config-Vertrag (Schema)? —
  fällt in Increment 5.
