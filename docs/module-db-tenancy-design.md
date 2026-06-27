# Inc 9 — Erzwungene Mandanten-Konformität von Modul-DB-Tabellen (Design)

**Problem (DB-Analogon zu Inc 8 / `docs/module-storage-convention-design.md`):** Eine
mandanten-tragende Modul-Tabelle muss `tenant_id` + RLS + eine fail-closed Tenant-Policy
führen — sonst ist sie (a) **nicht isoliert** = Cross-Tenant-Leak und (b) unsichtbar für
Backup-Scope + Verbrauchs-Footprint (beide entdecken Modul-Tabellen per Introspektion
über `tenant_id` + RLS). Bis Inc 9 war das **Konvention**; das alte Install-Gate prüfte
nur „≥1 RLS-Tabelle + ≥1 Policy im Schema".

**Hebel ≠ wie bei Dateien:** Es gibt keinen einzelnen Query-Chokepoint — aber **Postgres-RLS
erzwingt die Trennung bei jeder Query von selbst, sobald die Tabelle korrekt aufgesetzt
ist** (NOBYPASSRLS-App-Rolle, `Db::privileged()` für Module unerreichbar, FORCE RLS). Die
ungeschützte Fläche ist also nur die **Form der Tabelle zur Erstellungszeit**. Deshalb
**kein** Runtime-Query-Wrapper, sondern Schema-/Install-/Autoren-Zeit.

## Stufen (alle Core; Module adoptieren per Hand-off — Boundary)

- **9a — Helfer.** `core.create_tenant_table()` / `core.add_tenant_unique()` (SECURITY
  INVOKER, EXECUTE an PUBLIC) bauen die kanonische Form per Konstruktion: `tenant_id uuid
  NOT NULL DEFAULT core.current_tenant()`, ENABLE+FORCE RLS, Policy
  `core.rls_bypass() OR tenant_id = core.current_tenant()` (USING+WITH CHECK), tenant-first
  UNIQUE.
- **9b — Manifest `tables`.** Nur AUSNAHMEN deklarieren: undeklariert = tenant-scoped (muss
  konform sein); eine echte modul-globale Tabelle wird per `scope: global` + `reason`
  ausgeklinkt (die auditierbare Ausnahmeliste des Gates).
- **9c — Install-Gate (die Wall, scharf).** `assertTenantTablesConform()` prüft pro
  Tabelle im `mod_<key>`-Schema gegen den Live-Katalog: `tenant_id` uuid NOT NULL DEFAULT
  current_tenant, RLS enabled **und** forced, eine Policy die `tenant_id` an
  `current_tenant()` bindet in USING **und** WITH CHECK, UNIQUEs mit `tenant_id` zuerst.
  Verstoß → Aktivierung verweigert (Rollback). `forceRls` läuft für **alle** Module vor dem
  Gate (essenziell, damit RLS auf den modul-eigenen Tabellen tatsächlich greift; seit Inc 10
  in `ModuleTableRls::forceRls`). **Strukturelles** Gate — Policy-*Logik* ist per Leak-Tests
  gedeckt.
- **9d — module_lint Migrations-Scan (advisory).** Warnt aggregiert bei einer erstellten
  Tabelle ohne RLS, die nicht als global deklariert ist. Bewusst Warnung (nicht CI-Error):
  Module bauen Tenancy retrofit; das Gate ist die autoritative Prüfung.
- **9e — Gate von `is_scoped` entkoppelt.** Das Gate prüft jetzt JEDE Basistabelle in
  jedem Modul-Schema (konform oder global), unabhängig von `is_scoped`. Motiviert vom
  Connector (globale Capability `is_scoped:false`, aber tenant-scoped Daten) — ohne die
  Entkopplung wäre er übersprungen worden. `is_scoped` fügt nur noch die Regel hinzu: ein
  Modul mit scoped Ressource muss ≥1 Tabelle besitzen.

## Bekannte Grenzen / Reste (adversarial reviewt)
- **Policy-Logik:** Das Gate beweist die tenant-Form *strukturell*, nicht die Korrektheit
  der Policy-Logik (z. B. `tenant_id <> current_tenant()` oder ein Decoy-`current_tenant`).
  Gedeckt durch die verpflichtenden NOBYPASSRLS-Leak-Tests der Module (selbst-vereitelnd).
- **`global`-Ausnahme:** ein Modul kann eine tatsächlich tenant-tragende Tabelle als global
  ausklinken — bewusst auditierbar (Manifest, signiert), kein stiller Pfad.
- ~~`is_scoped`-Trigger~~ **GESCHLOSSEN in 9e:** das Gate prüft jede Tabelle unabhängig
  von `is_scoped` (motiviert vom Connector).
- **Tenant-Propagation in Modul-Beiträge:** Module laufen **in-process** (seit Inc 10 der
  einzige Pfad), also erben Modul-Beiträge auf dem **Request-Pfad** (Web/API/Listener) den
  bereits gesetzten RLS-Zeilenkontext (`app.current_tenant_id`) der Request-Transaktion — ein
  Modul liest/schreibt seine Tenant-Tabellen damit automatisch korrekt. Der einzige Rest:
  `ScheduledTaskRunner` ruft Tasks mit `['bypass'=>true]` (System-Kontext, kein Tenant) —
  korrekt für system-weite Tasks, aber ein *per-Tenant* Modul-Scheduled-Task bräuchte die
  Core-Tenant-Iteration (`TenantIterator`); heute kein Konsument, daher zurückgestellt.

## Modul-Adoption (Hand-off, NICHT Core) — Gate ist scharf
Ticketing + KB müssen VOR der nächsten (Re-)Installation: (1) jede echte globale Tabelle im
Manifest als `tables[].scope=global` (mit reason) deklarieren — `module_lint` zeigt sie an
(ticketing: `api_tokens`, `notification_types`; KB: `system_settings` u. a.); (2) bestätigen,
dass jede tenant-Tabelle konform ist (FORCE liefert Core; tenant-Uniques prüfen);
insbesondere `api_tokens` (tenant_id ohne bindende Policy) konform machen ODER global
deklarieren. Neue Tabellen via `core.create_tenant_table()`.
