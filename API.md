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

| Scope          | Erlaubt                          |
|----------------|----------------------------------|
| `me:read`      | `GET /api/v1/me`                 |
| `health:read`  | `GET /api/v1/health`             |
| `modules:read` | `GET /api/v1/modules`            |
| `*`            | alle Scopes (Wildcard)           |

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

## Beispiel

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://host/api/v1/me
```

## Erweiterung

Neue Endpunkte: Controller unter `src/Controller/Api/V1/` (von `ApiController`
ableiten, `requireScope()` nutzen, `json()` zurückgeben) + Route in
`config/routes.php` (Prefix `Api/V1`) + ggf. neuen Scope in
`TokenService::KNOWN_SCOPES`. Die `ApiAuthMiddleware` deckt alle `/api/`-Pfade ab.

> Hinweis: Rate-Limiting/Quotas sind für v1 nicht enthalten (späterer Ausbau).
