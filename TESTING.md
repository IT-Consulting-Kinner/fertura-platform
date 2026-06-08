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

## CI

`.github/workflows/ci.yml` startet einen PostgreSQL-17-Dienst, installiert den
PG-17-Client (für den Backup-Roundtrip), PHP 8.3 mit `sodium`/`zip` und führt
`vendor/bin/phpunit` aus.
