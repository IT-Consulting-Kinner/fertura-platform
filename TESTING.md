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
| **Out-of-Process** | `Service/Module/OutOfProcessIsolationTest` | Eigene DB-Rolle, Migrationen als Rolle, FORCE RLS, Supervisor-Spawn, `__probe`-Isolation, Echo-RPC, Enforcement |
| **Sessions (HA)** | `Session/DatabaseSessionTest` | DB-Session-Store: Schreiben/Lesen/Update/Löschen, Sichtbarkeit über zweite Instanz, GC |
| **Feature-Flags** | `Service/System/FeatureFlagsTest`, `Controller/ApiFeatureFlagTest` | env-Parsing; API-Gating (404 aus / 401 an); Health-`features` |
| **Upgrade-Pfad** | `Service/Update/ModuleUpdateTest` | Migrationsvorschau, Update mit Wiederherstellungspunkt (nur bei ausstehenden Migrationen), Downgrade-Schutz, Rollback-Kaskade (erhält installierte Daten) |

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

`.github/workflows/ci.yml` startet einen PostgreSQL-17-Dienst, installiert den
PG-17-Client (für den Backup-Roundtrip), PHP 8.3 mit `sodium`/`zip` und führt
`vendor/bin/phpunit` aus.

## Statische Analyse & Coverage (CI)

Die CI (`.github/workflows/ci.yml`) führt neben PHPUnit aus:

- **PHPStan Level 8 (blockierend, Baseline-gated):** `vendor/bin/phpstan analyse`.
  Bestehende Befunde sind in `core/phpstan-baseline.neon` grandfathered (456 zum
  Einführungszeitpunkt); **neue** Fehler lassen die CI rot werden. Die Baseline
  ist schrittweise abzubauen — beim Anfassen einer Datei deren Einträge entfernen
  und die echten Befunde beheben. Baseline neu erzeugen:
  `php -d memory_limit=2G vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`.
- **PHPCS (informativ, nicht blockierend):** `vendor/bin/phpcs --report=summary`
  (CakePHP-Standard; Altbestand fehlender Docblocks, daher vorerst nicht als Gate).
- **Coverage:** PHPUnit läuft mit `--coverage-text` (pcov) — sichtbar im CI-Log.
  Noch kein hartes Mindest-Gate; sobald ein Basiswert etabliert ist, kann eine
  Schwelle ergänzt werden.

Lokal (im `core`-Container): `vendor/bin/phpstan analyse`, `composer cs-check`.
