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
4. **`op_admins`** (Operator-Admin-Verwaltung, nur Operator-Mandant-User) als eigener gescopeter
   Bereich.
5. **Per-Tenant-Modul-Enable + -Config** (`tenant_modules`, Dispatch/Nav/Listener tenant-aware,
   Core/Modul-Config-Contract).
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
