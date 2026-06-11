# Externe API (v1)

Schlanke, token-authentifizierte JSON-API des Core (Kap. 29). Sie ergänzt die
in-process Modul-Interfaces um einen externen Zugang für Automatisierung/
Integration. Basis-Pfad: `/api/v1`.

## Authentifizierung

Bearer-Token im `Authorization`-Header:

```
Authorization: Bearer ftra_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

- Tokens werden in der GUI unter **Admin → API-Tokens** (`/admin/tokens`)
  erzeugt; der Klartext wird **nur einmal** angezeigt (gespeichert wird nur der
  SHA-256-Hash).
- Ein Token bindet an einen Benutzer; die Autorisierung läuft über dessen Rechte
  (RLS/Permissions). Die **Scopes** des Tokens schränken zusätzlich ein.
- Optionales Ablaufdatum; jederzeit widerrufbar. Inaktive Benutzer → kein Zugriff.
- Kein CSRF (Token statt Session); keine Weiterleitung — Fehler sind JSON.

### Fehler

| Status | `error`              | Bedeutung                                   |
|--------|----------------------|---------------------------------------------|
| 401    | `missing_token`      | Kein `Authorization: Bearer` gesendet.      |
| 401    | `invalid_token`      | Unbekannt/abgelaufen/widerrufen/Benutzer inaktiv. |
| 403    | `insufficient_scope` | Token hat den geforderten Scope nicht (`required`). |

## Scopes

| Scope          | Erlaubt                                                              |
|----------------|---------------------------------------------------------------------|
| `me:read`      | `GET /api/v1/me`, `/api/v1/notifications*`, `GET /api/v1/search`     |
| `health:read`  | `GET /api/v1/health`                                                 |
| `modules:read` | `GET /api/v1/modules`                                                |
| `audit:read`   | `GET /api/v1/audit` (NDJSON-Export für Compliance/SIEM)              |
| `scim:manage`  | `/api/scim/v2/*` (SCIM-2.0-Provisioning)                            |
| `*`            | alle Scopes (Wildcard)                                               |

Die in der GUI auswählbaren Scopes stehen in `TokenService::KNOWN_SCOPES`; `*`
deckt zusätzlich jeden modul-registrierten Endpunkt-Scope (P07) ab.

## Endpunkte

### `GET /api/v1/me` — Scope `me:read`
```json
{ "user_id": "...", "username": "admin", "email": "...", "locale": null,
  "scopes": ["me:read"], "token_id": "..." }
```

### `GET /api/v1/health` — Scope `health:read`
```json
{ "status": "degraded",
  "subsystems": { "database": {"status":"up"}, "workers": {"status":"degraded"}, ... } }
```

### `GET /api/v1/modules` — Scope `modules:read`
```json
{ "modules": [ {"module_key":"...","name":"...","version":"1.0.0","type":"main","status":"active"} ],
  "count": 1 }
```

### `GET /api/v1/audit` — Scope `audit:read`
NDJSON-Export des Audit-Logs für externe Compliance-/SIEM-Pulls (keyset-paginiert,
gestreamt). Filter per Query (`from`, `to`, `action`, `entity_type`, `entity_id`,
`module_key`, `actor_user_id`, `with_values`). Eine JSON-Zeile je Ereignis.

### `GET /api/v1/search` — Scope `me:read`
Volltext-/Hybrid-Suche, auf den Token-Inhaber gefiltert (`q`, `mode=fts|hybrid`).

### `GET /api/v1/notifications` · `POST /{id}/read` · `POST /read-all` — Scope `me:read`
Benachrichtigungen des Token-Inhabers (P09).

### `GET /api/v1/openapi.json`
Maschinenlesbare OpenAPI-3.1-Beschreibung der v1-API (P07).

### `… /api/v1/m/{moduleKey}[/{path}]` — modul-definierter Scope
Von Modulen registrierte Endpunkte (P07); der geforderte Scope kommt aus der
Routen-Registrierung des Moduls.

### SCIM 2.0 — `…/api/scim/v2/Users` · `/ServiceProviderConfig` — Scope `scim:manage`
Identity-Provisioning nach RFC 7643/7644 (Users-Ressource: List/Get/Create/
Replace/Patch/Delete, RFC-konforme Filter/Fehlerform), E130.

## Beispiel

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://host/api/v1/me
```

## Erweiterung

Neue Endpunkte: Controller unter `src/Controller/Api/V1/` (von `ApiController`
ableiten, `requireScope()` nutzen, `json()` zurückgeben) + Route in
`config/routes.php` (Prefix `Api/V1`) + **neuen Scope in
`TokenService::KNOWN_SCOPES` eintragen**, damit er per GUI als Least-Privilege-
Token vergeben werden kann (sonst nur über `*`). Die `ApiAuthMiddleware` deckt
alle `/api/`-Pfade ab.

> **Rate-Limiting:** Aktiv über `ApiRateLimitMiddleware` (P07), direkt nach der
> Token-Auth eingehängt — Begrenzung pro Token (bzw. pro IP ohne Token). Greift
> nur bei aktivem `FEATURE_API`. Grenzwerte über die Settings konfigurierbar.
