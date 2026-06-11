# Testen

Der Core hat eine PHPUnit-Suite mit **Unit-** und **Integrationstests**. Die
Integrationstests laufen gegen eine echte **PostgreSQL-Test-Datenbank** (kein
Mock), die der CakePHP-`Migrator` aus den Migrationen aufbaut.

## Ausführen

```bash
# Im laufenden Stack (empfohlen – PG-Client/Tools vorhanden):
docker compose exec core vendor/bin/phpunit

# Einzelner Pfad:
docker compose exec core vendor/bin/phpunit --filter ModuleLifecycleTest

# Mit Coverage + Ratschet-Gate (schlägt fehl, wenn unter coverage-min.txt):
docker compose exec core composer test-coverage

# Mutation-Testing der Sicherheitskerne (langsam; optionales Qualitätswerkzeug):
docker compose exec core composer mutation
```

Die Test-DB-Verbindung kommt aus `DATABASE_TEST_URL` (in `docker-compose.yml`
gesetzt, Default `postgres://fertura:fertura@db/fertura_test`). Der Migrator baut
das Schema bei abweichendem Migrationsstand neu. Die DB muss existieren
(`CREATE DATABASE fertura_test`), die Tabellen erzeugt der Migrator.

`bin/cake` benötigt sie nicht – nur die Tests.

## Abdeckung der kritischen Pfade

Integrationstests gegen DB + echte Services (Review-Punkt 1):

| Pfad | Datei | Was geprüft wird |
| --- | --- | --- |
| **Lifecycle + RLS** | `Service/Module/ModuleLifecycleTest` | Install/Activate/Deactivate/Delete des Fixture-Moduls; Schema/Contracts/RLS-Policy angelegt; **RLS-Durchsetzung** über eine NOBYPASSRLS-Rolle (eigene/öffentliche Zeilen sichtbar, fremde nicht; Bypass sieht alles); Abbruch bei `is_scoped`-Ressource ohne RLS (E47) inkl. Rollback |
| **Trust/Signatur** | `Service/Security/TrustChainTest` | Root→Publisher-Kette mit echten Ed25519-Schlüsseln; gültige Signatur, Manipulation, Publisher-Mismatch, Widerruf (Publisher **und** Root), Gültigkeitsfenster (abgelaufen / noch nicht gültig) |
| **Backup/Restore** | `Service/Backup/BackupRoundtripTest` | Echtes `pg_dump`+Stores in ein verifiziertes ZIP; Prüfsummen-Verifikation; nicht-destruktiver Probe-Restore in eine Scratch-DB; AES-256 (richtiges vs. falsches Passwort) |
| **i18n** | `Service/I18n/LocaleResolutionTest` | Versions-Gate exakt/same-major/Major-Mismatch; Pack-Status-Klassifikation; wählbare Locales (Englisch immer, Unverfügbare gefiltert) |
| **Auth/Token** | `Service/Api/TokenAuthTest` | TokenService (Klartext nur bei Erzeugung, Hash-only, Authentisierung, Ablauf, Widerruf); HTTP über `ApiAuthMiddleware` (`/api/v1/me`): gültig → 200, ohne Token → 401, falscher Scope → 403 |
| **Out-of-Process** | `Service/Module/OutOfProcessIsolationTest` | Eigene DB-Rolle, Migrationen als Rolle, FORCE RLS, Supervisor-Spawn, `__probe`-Isolation, Echo-RPC, Enforcement |
| **Sessions (HA)** | `Session/DatabaseSessionTest` | DB-Session-Store: Schreiben/Lesen/Update/Löschen, Sichtbarkeit über zweite Instanz, GC |
| **Feature-Flags** | `Service/System/FeatureFlagsTest`, `Controller/ApiFeatureFlagTest` | env-Parsing; API-Gating (404 aus / 401 an); Health-`features` |
| **Upgrade-Pfad** | `Service/Update/ModuleUpdateTest` | Migrationsvorschau, Update mit Wiederherstellungspunkt (nur bei ausstehenden Migrationen), Downgrade-Schutz, Rollback-Kaskade (erhält installierte Daten) |
| **MFA (TOTP)** | `Service/Security/MfaServiceTest`, `Controller/MfaControllerTest`, `Controller/AuthMfaFlowTest` | Zwei-Stufen-Enrollment mit Bestätigung, Replay-Schutz (±1 Zeitfenster, Zeitschritt nicht doppelt), Recovery-Codes (Hash-only, Single-Use), Login-Gate bei aktivem TOTP |
| **Passkeys (WebAuthn)** | `Service/Security/WebAuthnServiceTest` | Eigener CBOR/COSE-Codec, ES256/RS256-Assertion-Verifikation, Sign-Counter, Challenge-Bindung |
| **SSO (OIDC/SAML)** | `Controller/SsoControllerTest` | RelayState-/Nonce-Bindung, Single-Redeem, Session-Fixation-Erneuerung, lokale-MFA-vs-Föderation |
| **SCIM 2.0** | `Controller/Api/Scim/ScimUsersTest` | Provisioning nach RFC 7643/7644 (List/Get/Create/Replace/Patch/Delete), Scope `scim:manage` |
| **Audit-Export** | `Controller/AuditExportEndpointTest` | NDJSON-Export (`/api/v1/audit`), Keyset-Pagination, Filter, Scope `audit:read` |

Zusätzlich Unit-Tests: `BackupPathTest`, `TrustValidityTest`, `TokenScopeTest`,
`PoDocumentTest`, `ApplicationTest` (Middleware-Reihenfolge).

## Hinweise für neue Integrationstests

- **Keine CakePHP-Fixtures** – die Tests verwalten ihre Zeilen selbst
  (eindeutige Schlüssel/Suffixe) und räumen in `tearDown` auf. `TestCase` ohne
  Fixtures wrappt nicht in eine Transaktion → Schreibvorgänge sind real.
- **DB-Verbindung:** Services greifen über `ConnectionManager::get('default')`
  bzw. `App\Infrastructure\Db::privileged()` – im Testlauf beide die Test-DB.
- **RLS prüfen:** Der Superuser umgeht RLS. Für echte Durchsetzung in einer
  Transaktion auf eine NOBYPASSRLS-Rolle wechseln (`SET LOCAL ROLE …`) und den
  Kontext via `set_config('app.current_user_id', …, true)` setzen, dann
  zurückrollen.

## Manuelle Harnesses (Shell)

Ergänzend zu PHPUnit, im Container ausführbar (`docker compose exec core sh …`):

- `tests/scripts/migration_reversibility_check.sh` — fährt **alle** Core-
  Migrationen auf einer Wegwerf-DB hoch und vollständig zurück
  (`migrate` → `rollback -t 0`): beweist die Down-Reversibilität.
- `tests/scripts/module_isolation_check.sh` — Out-of-Process-Isolationsnachweis
  (eingeschränkte Rolle, bereinigte Umgebung, RPC-`__probe`).

## CI

> **Stand `.github/workflows/ci.yml`:** Die Workflow-Datei ist noch weitgehend das
> CakePHP-App-Skeleton-CI. Der `testsuite`-Job läuft gegen **SQLite**
> (`DATABASE_TEST_URL=sqlite://…`) und deckt damit die **PostgreSQL-abhängige
> Integrationssuite nicht** ab (RLS, `pg_dump`-Roundtrip, pgvector,
> Advisory-Locks brauchen echtes PostgreSQL). **Maßgeblich** ist daher der
> lokale/Docker-Lauf gegen PostgreSQL 17 (siehe „Ausführen"). Der
> `coding-standard`-Job ist hingegen wirksam: er führt PHPStan und PHPCS aus.
>
> Offen (Folgeaufgabe): den `testsuite`-Job auf einen PostgreSQL-17-Service +
> PG-Client umstellen, damit die Integrationssuite auch in CI greift.

## Statische Analyse & Coverage

- **PHPStan Level 8 (blockierend, Baseline-gated):** im `coding-standard`-CI-Job
  und lokal via `vendor/bin/phpstan analyse`. Bestehende
  Befunde sind in `core/phpstan-baseline.neon` grandfathered; **neue** Fehler
  lassen den Lauf rot werden. Die Baseline wird schrittweise abgebaut (beim
  Anfassen einer Datei deren Einträge entfernen und die echten Befunde beheben).
  Neu erzeugen:
  `php -d memory_limit=2G vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`.
- **PHPCS (informativ, nicht blockierend):** `composer cs-check` (CakePHP-Standard).
- **Coverage-Ratschet (Gate):** `composer test-coverage` erzeugt einen
  Clover-Bericht und ruft `php bin/coverage-check.php` auf — der Lauf **schlägt
  fehl**, wenn die Zeilenabdeckung unter den committeten Schwellwert in
  `core/coverage-min.txt` fällt. Der Schwellwert wird über die Zeit **nur
  angehoben** (Ratschet), nie gesenkt. Derzeit lokal/manuell ausgeführt (im CI
  läuft `phpunit` ohne Coverage).
- **Mutation-Testing (optional):** `composer mutation` (Infection), eng begrenzt
  auf die `security`-Testsuite (Krypto/Signaturkette, TOTP/WebAuthn, Lizenz-
  Statusmaschine, BREAD-Permissions, Auth). Rechenintensiv → kein blockierendes
  Gate, sondern gezieltes Härtungswerkzeug. Konfiguration in `core/infection.json5`.

Lokal (im `core`-Container): `vendor/bin/phpstan analyse`, `composer cs-check`,
`composer test-coverage`, `composer mutation`.
