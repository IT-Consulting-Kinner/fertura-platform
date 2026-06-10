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

**Nächste Schritte zum SaaS-Vollausbau:**
1. **Pre-Auth-Mandantenauflösung** per Host/Subdomain (heute: aus dem Benutzer
   nach Login). Hook in einer frühen Middleware, der `app.current_tenant_id` schon
   vor dem Login setzt (z. B. für mandantenspezifische Login-Themes/SSO-Provider).
2. **RPC/Worker-Propagation** des Mandanten an Out-of-Process-Hosts und Event-
   Listener (heute fail-closed unsichtbar ohne Bypass).
3. **Mandanten-Verwaltungs-GUI** (Admin) und Benutzer-↔-Mandant-Zuweisung im UI.
4. **Mandanten-Scoping** der gewünschten Core-Tabellen (heute owner-scoped,
   Single-Org) bzw. konsequente Adoption des Musters in den Modulen.
