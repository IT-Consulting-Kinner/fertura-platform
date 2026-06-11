# Fertura – Modulare Anwendungsplattform

Modulare Anwendungsplattform auf Basis von **CakePHP 5 / PHP 8.3+ / PostgreSQL**.
Der Core ist die technische Plattform; Fachlogik liegt in installierbaren
Main-Modulen und Extension-Modulen.

## Dokumentation

**Anforderungen & Planung**

| Datei | Inhalt |
| --- | --- |
| `Plattform_Anforderungsdokument_v6_25.md` | Plattform-Anforderungen (Core, Module, Contracts, BREAD, Lifecycle, Updates) – v6.25, mit fortlaufendem Änderungsprotokoll |
| `IMPLEMENTATION_PLAN.md` | Umsetzungstagebuch (E-Nummern): jede Entscheidung mit Begründung |
| `Modul_Ticketing_Anforderungsdokument_v6_2.md` | Ticketing-Main-Modul – v6.2 (auf Plattform v6.25 ausgerichtet) |
| `Alignment_Ticketing_v6.1_zu_Plattform_v6.25.md` | Alignment-Delta Modul → Plattform |
| `PROGRAM_TIER123.md` | Programm-Tier-Einteilung (P-Nummern) der Ausbaustufen |
| `NOTES.md` | Projektnotizen / Nachverfolgung |

**Technik & Betrieb** (Themen-Leitfäden)

| Datei | Inhalt |
| --- | --- |
| `API.md` | Externe token-authentifizierte JSON-API v1 (+ SCIM 2.0) |
| `SIGNING.md` / `LICENSING.md` | Paket-/Lizenzsignatur (Root→Publisher-Kette) bzw. Lizenzmodell |
| `BACKUP.md` / `RUNBOOK.md` | Sicherung/Wiederherstellung bzw. Betriebs-Runbook |
| `TENANCY.md` / `SCALING.md` | Mandantenfähigkeit bzw. Skalierung/HA |
| `I18N.md` / `A11Y.md` | Mehrsprachigkeit/Locale-Store bzw. Barrierefreiheit |
| `DB_CONVENTIONS.md` / `TESTING.md` | DB-/Schema-Konventionen bzw. Test-/CI-Setup |
| `MODULE_DEVELOPMENT.md` / `MODULE_UI.md` | Modulentwicklung (SDK) bzw. Modul-UI-Beiträge |

## Status

Core-Plattform weit ausgebaut: Auth/MFA (TOTP + Passkeys/WebAuthn), SSO (OIDC/
SAML) und SCIM-Provisioning, BREAD/RLS-Rechtemodell, Modul-Lifecycle mit
Out-of-Process-Isolation, Contract-/Capability-Registry, Outbox/Events,
Automatisierung/Workflows, Suche (FTS + Vektor), Backup/Restore inkl. Off-Site,
Lizenz-/Signaturkette, Audit-Log mit SIEM-Export, Health/Metrics und eine
vollständige Admin-GUI. Test-/CI-Reife: PHPUnit-Integrationssuite gegen echte
PostgreSQL, PHPStan Level 8 (Gate), Coverage-Ratschet, Mutation-Testing
(Infection) für die Sicherheitskerne. Produktiver Code (`core/src`) durchgängig
englisch kommentiert. Vor Release: juristische Lizenzprüfung ausstehend.

## Lizenz

**AGPL-3.0 ausschließlich für den Core** (siehe `LICENSE`). **Alles
außerhalb des Core** — Main-Module und Extension-Module jeder Art — ist
über eine **Section-7-Zusatzerlaubnis** ausgenommen und darf eigene (auch
proprietäre) Lizenzen tragen, solange es nur über die definierten
Erweiterungsschnittstellen interagiert. Details in `LICENSING.md`.
Hinweis: Lizenz-Entwurf, juristische Prüfung vor Release ausstehend.
