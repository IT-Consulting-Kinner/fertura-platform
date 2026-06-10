# Mandantenfähigkeit (Multi-Tenancy)

Der Core bringt ein **Mandanten-Fundament** mit, das den Single-Org-Betrieb nicht
verändert und sich später zu echter Mehrmandantenfähigkeit ausbauen lässt.

## Modell

- `core.tenants` — die Mandanten. Ein fest-ID'ter **Default-Mandant**
  (`00000000-0000-0000-0000-000000000001`, Schlüssel `default`) existiert immer.
- `core.users.tenant_id` — jeder Benutzer gehört zu genau einem Mandanten
  (Default = Default-Mandant). INSERTs ohne `tenant_id` (SSO-Provisioning,
  Einladung) erhalten automatisch den Default → **Single-Org bleibt unverändert**.
- **Aktiver Mandant pro Request:** `TransactionRlsMiddleware` leitet ihn aus dem
  angemeldeten Benutzer ab und setzt ihn via `RlsContext` als
  `app.current_tenant_id` (SET LOCAL, transaktions-lokal, pooling-fest).

Verwaltung: `App\Service\Tenant\TenantService` (`all()`, `get()`, `create()`,
`assignUser()`, `tenantIdForUser()`).

## Mandanten-scoped Daten (für Module)

Eine mandanten-bezogene Tabelle bekommt eine `tenant_id`-Spalte und eine
RLS-Policy, die den per-Request-Kontext auswertet:

```sql
ALTER TABLE mod_x.things ADD COLUMN tenant_id uuid NOT NULL;
ALTER TABLE mod_x.things ENABLE ROW LEVEL SECURITY;
-- FORCE, damit die Policy auch für den Tabellen-Owner greift (nicht nur die App-Rolle):
ALTER TABLE mod_x.things FORCE ROW LEVEL SECURITY;
CREATE POLICY p_tenant ON mod_x.things
    USING      (tenant_id = core.current_tenant() OR core.rls_bypass())
    WITH CHECK (tenant_id = core.current_tenant() OR core.rls_bypass());
```

`core.current_tenant()` liefert den aktuellen Mandanten (uuid) bzw. **NULL**, wenn
kein Kontext gesetzt ist.

## Fail-closed (kein Leck bei Teil-Einführung)

Ohne Mandantenkontext ist `core.current_tenant()` NULL → `tenant_id = NULL` ist
nie wahr → mandanten-bezogene Zeilen sind **unsichtbar**. Eine nur teilweise
eingeführte Mandantentrennung kann daher nicht über Mandantengrenzen lecken.
`core.rls_bypass()` (Worker/System) sieht alles.

`core.users` erhält bewusst **keine** sperrende Mandanten-Policy: der Pre-Auth-
Login muss Benutzer lesen können, bevor ein Kontext existiert.

## Was bereits steht / nächste Schritte

**Steht:** Mandanten-Tabelle + Default-Mandant, `users.tenant_id`, Kontext-
Propagation in den Request (RLS), `current_tenant()`-Helfer, `TenantService`,
Fail-closed-Garantie.

**Inzwischen umgesetzt:**
- ✅ **Pre-Auth-Mandantenauflösung** per Host/Subdomain — `App\Service\Tenant\TenantResolver`
  (exakte `tenants.domain` oder Konvention Subdomain==Schlüssel); die
  `TransactionRlsMiddleware` setzt den Mandanten pre-auth aus dem Request-Host
  (mandantenspezifische Login-/SSO-Oberfläche).
- ✅ **RPC-Propagation** des Mandanten an isolierte Modul-Hosts — `RemoteInvoker`
  reicht `tenant_id` mit, `bin/module-host.php` setzt `app.current_tenant_id` je Aufruf.
- ✅ **Mandanten-Verwaltungs-GUI** (`/admin/tenants`): anlegen (inkl. Branding),
  sortier-/paginierbare Liste, **Aktivieren/Suspendieren** (Sammelaktion),
  **Benutzer-↔-Mandant-Zuweisung**.
- ✅ **Such-/Embedding-Index mandantenscharf** — `search_index`/`embeddings` haben
  `tenant_id` (Default-Mandant für Bestand), pro-Mandant-Eindeutigkeit, und die
  Service-Abfragen filtern mandantenscharf (anwendungsseitig, konsistent zur
  bestehenden Owner-Sichtbarkeit). Schließt das Leck öffentlicher Dokumente.
- ✅ **Mandant am Event** — `event_outbox.tenant_id` (vom `OutboxPublisher` aus dem
  Kontext gesetzt); der Worker setzt ihn beim Verarbeiten, sodass abgeleitete
  Aktionen/Listener im richtigen Mandanten arbeiten.
- ✅ **Cross-Tenant-Host-Policy** — ein angemeldeter Benutzer auf der Domain eines
  fremden Mandanten wird mit `403` abgewiesen (`tenancy.enforce_host_match`, Default an).
- ✅ **Mandantenspezifisches Branding** — `tenants.brand_name`/`logo_url`, angezeigt
  auf der (host-aufgelösten) Login-Seite.

**Verbleibende Schritte zum SaaS-Vollausbau:**
1. **Tenant-Scoping in den Modulen** — Modul-Datentabellen adoptieren das
   dokumentierte Muster (oben); der Core-Index ist bereits gescoped.
2. **Pro-Mandant-Settings** über das Branding hinaus (z. B. Limits, Feature-Flags je
   Mandant) und Self-Service-Mandantenanlage/-Lifecycle (Abrechnung etc.).

*Hinweis zur Konsistenz:* Die Such-/Embedding-Tabellen werden **anwendungsseitig**
mandantengefiltert (wie ihre bestehende Owner-Sichtbarkeit), nicht per RLS — ihre
Schreiblast und die CLI-/Worker-Kontexte machen RLS hier teurer als den Nutzen.
Mandanten-eigene Modultabellen nutzen weiterhin das RLS-Muster (`core.current_tenant()`).
