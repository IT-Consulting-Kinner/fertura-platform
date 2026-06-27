# Inc 10 — Vereinheitlichung auf in_process + Capability-Gate (Plan)

> **Status: UMGESETZT (as-built, 27.06.2026).** Alle 8 Stufen abgeschlossen; volle Suite 663 grün,
> PHPStan/PHPCS grün. Out-of-Process-Code + Doku entfernt; `forceRls` lebt in `ModuleTableRls`;
> das Capability-Gate ist über `ManifestLinter::lintCapabilities` + `ModuleLifecycle::install`
> scharf. Festgehalten als Plattform-**Entscheidung 187** + Changelog **6.117** im
> Anforderungsdokument (Kap. 23.16.2 neu gefasst). Dieses Dokument bleibt als Entwurfs-/
> Begründungs-Referenz erhalten.

Plattform-Entscheidung: **Modul-Vertrauen über Signatur + Review (kuratierter Marktplatz)**,
nicht über Laufzeit-Isolation. Daher: out_of_process-Isolation **vollständig entfernen** und
in_process durch ein statisches **Capability-Allowlist-Gate** härten. Mandantentrennung kommt
unverändert aus RLS (Inc 9), unabhängig vom Prozessmodell.

## Capability-Gate (statisch, Install-Zeit, Modul-`src/` nur)
Matcher: **bare** Funktionsaufrufe (Wortgrenze, NICHT nach `->`/`::`), Modul-`src/` ohne
vendor/tests. Gegen die 3 echten Module validiert → kein False-Positive.
- **forbid-ERROR:** eval/create_function/assert(string)/preg_replace `/e`; exec/shell_exec/
  system/passthru/proc_open/popen/backtick/pcntl_exec; Reflection*/->setAccessible/Closure::bind*;
  new PDO/pg_connect/pg_pconnect; ConnectionManager::getConfig/setConfig/drop/alias; rohe FS
  (fopen/file_get_contents/file_put_contents/readfile/mkdir/rmdir/unlink/rename/copy/scandir/
  opendir/readdir/glob/chmod/chown); $$var/extract/compact; putenv/ini_set/ini_alter/dl.
- **WARN:** Socket-I/O (fwrite/fgets/stream_socket_*/fsockopen — IMAP nutzt das legitim), curl_*,
  serialize/unserialize, include/require mit nicht-konstantem Pfad, set_error_handler u. ä.
- **ALLOW (bewusst):** `ConnectionManager::get('default')`, `Db::exec`/`->exec`, `preg_replace` mit `/u`,
  `ModuleStorage->write`, eigene Paket-Datei-Reads.

## forceRls (KRITISCH) + isolation-Spalte
- `forceRls` wird von Inc 9c (Gate verlangt `relforcerowsecurity`) für ALLE Module gebraucht →
  **nach `App\Service\Module\ModuleTableRls` extrahieren** (verbatim), Gate-Check unverändert.
- `modules.isolation` (+ `db_role`, `db_role_secret`) + `module_install_jobs.isolation`: per **neuer
  Vorwärts-Migration** droppen — als LETZTER Schritt, nachdem kein Code sie mehr liest. Alt-Migrationen
  (20260608090000/100000) NICHT editieren.

## Löschen
RemoteInvoker, ModuleHostSupervisor, RpcCapabilityToken, ModuleDbRole, bin/module-host.php,
OutOfProcessIsolationTest, OutOfProcessPhase3Test, RpcCapabilityTokenTest, Fixtures
isolated_module + isolated_anon_module.

## Stufen (Suite bei JEDER grün)
1. **forceRls extrahieren** → ModuleTableRls; ModuleLifecycle + UpdateManager (unbedingt) umbiegen.
2. **isolation-Dispatch/Reads raus** (ContributionRuntime, CapabilityHandle, ApiRouteRegistry,
   Api/V1/ModuleController) + die 3 OOP-Tests + 2 Fixtures löschen.
3. **Lifecycle-OOP-Zweige** (install/activate/deactivate/delete/rollback/setIsolation/assertIsolatable),
   Host-Supervision aus OutboxWorker, isolate/host/--isolation aus ModuleCommand.
4. **OOP-Klassen löschen** (jetzt referenzlos).
5. **isolation-Param** durch die Install-Job-Kette (ModuleInstallJobService/Runner/Handler, beide
   Controller, 2 Templates, ModuleInstallJobTest) — im Gleichschritt.
6. **Drop-Column-Migration** (CoreDropModuleIsolation).
7. **Capability-Gate** + Unit-Test + Regression der echten Modul-Suites.
8. **Doku** (MODULE_DEVELOPMENT.md, Plattform_Anforderungsdokument.md Kap. 23.16).

## Risiken
forceRls-Regression (Gate lehnt sonst alles ab) · install-Param-Gleichschritt (sonst
ArgumentCountError) · Column-Drop-Ordering · Gate-False-Positive (Matcher-Grenzen) · Live-Install-
Copy (Container sieht Core-Änderungen erst nach Reinstall) · Boundary (kein Modulcode editieren).
