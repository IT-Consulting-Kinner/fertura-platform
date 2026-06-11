# Fertura Core

Die technische Plattform von **Fertura** auf Basis von **CakePHP 5 / PHP 8.3 /
PostgreSQL 17**. Der Core stellt Authentifizierung (lokal + MFA/Passkeys, SSO via
OIDC/SAML, SCIM-Provisioning), das BREAD-/RLS-Rechtemodell, Modul-Lifecycle mit
Out-of-Process-Isolation, die Contract-/Capability-Registry, Outbox/Events,
Automatisierung/Workflows, Suche (Volltext + Vektor), Backup/Restore (inkl.
Off-Site), die Lizenz-/Signaturkette, Audit-Log mit SIEM-Export sowie
Health/Metrics und eine vollständige Admin-GUI (gebündeltes Bootstrap 5) bereit.
Fachlogik liegt in installierbaren Main- und Extension-Modulen.

## Entwicklung

Der Stack läuft über Docker Compose (Dienste `db`, `core`, `web`, `worker`,
`mail`, `redis`, `marketplace`):

```bash
docker compose up -d
docker compose exec core bin/cake core_migrate     # Schema/Migrationen
docker compose exec core vendor/bin/phpunit        # Testsuite (gegen echtes PostgreSQL)
```

CLI-Kommandos: `docker compose exec core bin/cake <command>` (z. B. `backup`,
`module`, `trust`, `sso`, `permission`, `lang`, `secret`). Eine Übersicht aller
Kommandos liefert `bin/cake`.

## Qualität

- **PHPStan Level 8** (Baseline-gated): `vendor/bin/phpstan analyse`
- **Coding-Standard:** `composer cs-check` (CakePHP-Standard)
- **Coverage-Ratschet (Gate):** `composer test-coverage` (gegen `coverage-min.txt`)
- **Mutation-Testing (Sicherheitskerne):** `composer mutation` (Infection)

Details in [`../TESTING.md`](../TESTING.md).

## Dokumentation

Die maßgebliche Projekt- und Betriebsdokumentation liegt im Repository-Wurzel­
verzeichnis — Einstieg über [`../README.md`](../README.md). Themen-Leitfäden u. a.:
[`../API.md`](../API.md), [`../SIGNING.md`](../SIGNING.md),
[`../BACKUP.md`](../BACKUP.md), [`../TENANCY.md`](../TENANCY.md),
[`../MODULE_DEVELOPMENT.md`](../MODULE_DEVELOPMENT.md).

## Lizenz

**AGPL-3.0-only** für den Core (siehe `../LICENSE`). Module außerhalb des Core
sind über eine Section-7-Zusatzerlaubnis ausgenommen — Details in
[`../LICENSING.md`](../LICENSING.md).
