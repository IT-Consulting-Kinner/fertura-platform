# Inc 8 — Erzwungene per-Modul-Datei-Konvention (Design)

**Problem:** Per-Tenant-Dateien eines Moduls müssen unter `tenant/<id>/<module-key>/`
liegen, sonst erfasst sie weder das Per-Tenant-Backup (full **und** Scope) noch der
Verbrauchs-/Abrechnungs-Footprint. Bis Inc 8 war das reine **Konvention** — Ticketing
und KB schrieben Anhänge in den Storage-Root (`ticketing/…`, `knowledgebase/…`), also
außerhalb des Tenant-Baums (vgl. Memory `module-tenant-file-convention-gap`). Konvention
per Disziplin wird vergessen; Inc 8 erzwingt sie per **Konstruktion + Laufzeit + CI**.

## Stufen (alle Core; Module adoptieren per Hand-off — Boundary)

- **8a — Primitive.** `ModuleStorage::for('<key>')` (bzw. `TenantStorage::forModule`)
  liefert ein Handle, das jeden Pfad unter `tenant/<current-tenant>/<key>/` erzwingt, den
  Tenant **live** aus dem RLS-Kontext auflöst (korrekt auch bei tenant-iterierenden
  Workern) und ohne Tenant-Kontext **wirft** (fail-closed, nie `tenant//…`).
  `write()/writeStream()` geben den vollen Pfad zurück → Modul persistiert ihn als
  `storage_path`.
- **8b — Runtime-Guard.** `ContributionRuntime::call` (die **eine** Engstelle für
  Web/API/Listener/Collector/Task/Capability) setzt je Nicht-Core-Modulaufruf einen
  ambient `ModuleStorageScope` (nesting-fest, finally-restauriert). `StorageManager`
  verweigert dann Schreib-/Löschops außerhalb `tenant/<id>/<key>/` (Allowlist: `reports/`
  für Core-Exporte). Vergessen wird vom stillen Datenverlust zum lauten Fehler. Core-Code
  (`module_key='core'`) ist nie eingeschränkt; Lesezugriffe nie.
- **8c — Lint-Zaun.** `module_lint` (ManifestLinter::lintSource) meldet jede direkte
  `new StorageManager()`/`new TenantStorage()` im Modul-`src/` als Fehler → fängt es zur
  Autoren-/CI-Zeit und deckt die wenigen Einstiegspunkte, die nicht durch die Engstelle
  laufen (z. B. ein zur Bootstrap-Zeit aufgelöster Auth-Provider).

## Bekannte Grenzen / Rest

- **Out-of-process-Module:** laufen in eigenem, isoliertem Prozess (token-gesicherter
  Socket); ihr Datei-I/O berührt den Core-`StorageManager` nicht — die Konvention wird
  dort durch Prozess-/Dateisystem-Isolation getragen, nicht durch den in-process-Guard.
  Kein Loch, sondern bewusst außerhalb des in-process-Mechanismus.
- **PHP-Restluke (in-process):** ein Modul kann den Core umgehen (eigenes Flysystem /
  `fwrite`). Unverschließbar ohne Out-of-process-Isolation; der Lint-Zaun fängt den
  realistischen Fall (`new StorageManager()`).
- **`reports/`-Allowlist:** nicht tenant-scoped (Core-Exporte, Zufalls-Dateinamen, keine
  gezielte Überschreibung). Bewusst minimal gehalten.

## Modul-Adoption (Hand-off, NICHT Core)

Ticketing + KB stellen Schreib-/Lese-/Löschpfade auf `ModuleStorage::for('<key>')` um und
persistieren den zurückgegebenen vollen Pfad als `storage_path`. **Pre-release: kein
Backfill** (keine Produktivdaten; Dev-/Test-Dateien verwerfen). CI-Gate
(`bin/cake module_lint modules/<m>`) in die jeweilige Modul-CI / Integrations-Harness.
`module_lint` meldet aktuell die umzustellenden Dateien:
Ticketing `TicketService`, `MailIngestionService`, `MailHtmlRenderer`;
KB `ArticleService`, `AttachmentService`.
