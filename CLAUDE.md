# CLAUDE.md — Fertura Core

Fertura Core: a multi-tenant SaaS platform (CakePHP 5, PHP 8.3, PostgreSQL) with
row-level security and an operator/tenant admin separation. Modules and extensions
plug in via manifests; the running stack composes Core with the installed modules.
Core code lives under `core/`; the topic guides below sit at the repo root.

## Documentation

Consult the relevant guide before changing that area:

- `I18N.md` — internationalisation: symbolic keys, PO catalogs, Managed Locale Store.
- `MODULE_DEVELOPMENT.md` — module manifest, docking points, packaging.
- `MODULE_UI.md` — module admin-UI conventions.
- `DB_CONVENTIONS.md` — schema and naming rules.
- `TENANCY.md` — multi-tenancy model and RLS.
- `TESTING.md` / `TESTPLAN.md` — test strategy and plan.
- `SIGNING.md` — module signature / trust chain.
- `BACKUP.md` — operator DR and per-tenant backup.
- `API.md` — REST API surface.
- `A11Y.md`, `LICENSING.md`, `RUNBOOK.md`, `SCALING.md`, `PROGRAM_TIER123.md` —
  accessibility, licensing, ops runbook, scaling, program tiers.
- `IMPLEMENTATION_PLAN.md` — decisions / increments log.

## Conventions & contracts

- **i18n placeholders are sprintf, never ICU.** Core (the `default` domain) and every
  **module/extension domain** use sprintf placeholders (`%s`/`%d`) — never ICU
  (`{0}`/`{1}`). The global formatter is pinned to sprintf (`core/config/bootstrap.php`),
  and each module domain is loaded with an explicit sprintf `Package`
  (`core/src/I18n/StoreLocaleLoader.php`), whose own formatter wins over the global
  default. An ICU placeholder therefore renders raw — the argument is dropped.
  `bin/cake module_lint` warns on ICU `{n}` in a module's `locales/*.po`. See `I18N.md`.
