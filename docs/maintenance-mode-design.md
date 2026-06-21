# Design: GUI-gesteuerter Maintenance-/Quiesce-Modus mit Pflicht-Backup-Gate

Status: **beschlossen** (9 Entscheidungen fixiert, siehe §6). Grundlage ist die
adversariale Design-Review (10 Agents). Dies ist die Sicherheits-Hülle, die kritische
GUI-Aktionen (Modul-Install, Tenant-Provision, Trust-/Secret-Rotation) sicher macht.

## 1. Grundgedanke

Kombination aus vier etablierten Praktiken, neu als **erzwungene Sequenz** vor GUI-Aktionen:
**Wartungsfenster** + **Quiesce/Drain** + **Pre-Change-Snapshot mit Rollback** +
**Single-Operator-Lockout** (vgl. RDBMS `SET SINGLE_USER WITH ROLLBACK IMMEDIATE`).

Tragfähig **nur** als persistente, **DB-getragene** Zustandsmaschine — nicht als Erweiterung
des heutigen Datei-Flags. Grund: Fertura ist Multi-Instanz/Multi-Worker/Multi-Tenant-HA;
alle vorhandenen Bausteine (Datei-Flag, Settings-Cache, SIGTERM-Worker-Stop) sind
**instanzlokal**. Naiv umgesetzt ist das Gate **Scheinsicherheit**, die GUI-Aktionen
*gefährlicher* macht (inkonsistenter Snapshot + globaler Cross-Tenant-Rollback).

## 2. Die 5 CRITICAL-Stellen (aus dem Red-Team)

1. **Settings-Cache-Lag** (FileEngine, +5 min TTL): `MaintenanceMiddleware` liest den
   gecachten Wert → andere Instanzen lassen bis zu 5 min alle durch, Worker erzeugen weiter
   Jobs. → **Maintenance-Status nie über den cachebaren Settings-Pfad**; eigener ungecachter
   DB-Reader + `pg_notify('core_maintenance')`.
2. **Restore-Cutover blind**: `restore()` engaged nur das lokale `tmp/maintenance.flag`,
   setzt das DB-Setting nicht → andere Web-Nodes treffen die halb-restaurierte DB. → LB-Drain
   (Health 503) + DB-unabhängiges Cluster-Signal + periodischer Re-Check (1–2 s).
3. **Globaler Rollback verwirft Cross-Tenant-Daten**: `restore()` = `pg_restore --clean` über
   alle Schemas/Tenants. → aktionseigener transaktionaler Undo primär; globaler Restore nur
   separat berechtigter Notnagel mit Cross-Tenant-Warnung.
4. **Pflicht-Backup = Cross-Tenant-Voll-Export inkl. Secrets** (Verschlüsselung optional). →
   eigenes Backup-Recht + **erzwungene Verschlüsselung** + fester Pfad + Audit.
5. **Crash mid-action → permanenter Maintenance-Deadlock**. → persistente DB-State-Machine
   (`core.critical_action`) + **Recovery-Sweep** beim Start von Web UND Worker.

## 3. Fail-closed vs. Notausstieg (Kernkonflikt)

„Exit nur wenn stabil" ist datenintegritätsseitig korrekt — und tödlich bei hängender Aktion.
Auflösung (drei getrennte Dinge):
1. **„Stabil" = „keine *nicht-terminale* Aktion"** (nicht „alle erfolgreich").
   `running/verifying/rolling_back` blockieren den Exit; `failed/aborted/needs_manual_restore`
   sind zulässige Terminalzustände mit Operator-Quittung.
2. **Datenintegrität durch Idempotenz + Pre-Rollback-Snapshot**, nicht durch Einsperren. Bei
   Restore-Crash das Maintenance-Flag bewusst HALTEN (halb-restaurierte DB nie online).
3. **Notausstieg ist first-class**: CLI-Break-Glass `bin/cake maintenance:release` /
   `maintenance:reassign-actor <userId>` (umgeht das Web-Gate) + Lease/TTL → `operator_gone`.

**Kernsatz:** Maintenance darf nie ein irreversibler Web-only-Zustand sein. Fail-closed gilt
für *Daten*, nie für die Erreichbarkeit des Exits.

## 4. Ziel-Entwurf

### 4.1 Persistente Bausteine (DB als Single Source of Truth)
- **`core.maintenance_session`** `(id uuidv7, actor_user_id, allow_token_hash, status
  enum[active|operator_gone|closed], opened_at, heartbeat_at, scope)` — **Partial Unique Index
  `WHERE status <> 'closed'`** (`uq_maintenance_one_open`) erzwingt genau eine OFFENE Session
  (active ODER operator_gone); atomarer Eintritt via `INSERT … ON CONFLICT DO NOTHING`,
  Verlierer = 409.
- **`core.critical_action`** `(id, type, status enum[quiescing|backing_up|running|verifying|
  rolling_back|succeeded|failed|aborted|needs_manual_restore], backup_id, pre_rollback_backup_id,
  maintenance_session_id, heartbeat_at, fence_token, actor_id)`.
- **`core.worker.paused`** (DB-Setting, ungecacht) + Spalte `state` in `core.worker_heartbeats`.
- **`core.sessions.user_id`** (optional, für echten Mass-Logout) — sonst Post-Auth-Reject.

### 4.2 Zustandsautomat einer kritischen Aktion
```
                 ┌──────────── CLI break-glass / TTL operator_gone ────────────┐
                 │                                                              ▼
ENTER_MAINTENANCE ─→ QUIESCE ─→ PRE_ACTION_BACKUP ─→ ACTION ─→ VERIFY ─┬ ok ─→ STABLE ─→ EXIT
   atomarer       worker.paused   create() sync,    aktions-  post-    │
   INSERT,        + in-flight=0    erzwungen         eigene    action  └ fail ─→ ROLLBACK ─┬ ok ─→ STABLE
   actor+token    2× Fenster,      verschluesselt    Logik     health         (aktions-     │
   allow-cookie   deadline 120s    fail-closed       (locked)  -check)         eigen ODER    └ fail ─→ NEEDS_MANUAL_RESTORE
                                                                                pre-rollback-  (Flag HALTEN, Exit
                                                                                snapshot)      nur per Operator-Quittung)
```

### 4.3 Code-Andockpunkte
| Phase | Andockpunkt | Konkret |
|---|---|---|
| ENTER | `MaintenanceController` (RBAC `system.maintenance`) + `MaintenanceService` | atomarer INSERT; Allow-Token-Cookie; `pg_notify`; audit (System-Tenant-Scope) |
| Gate | **neue `SelectiveMaintenanceMiddleware` nach `AuthenticationMiddleware`** | Status **ungecacht** aus DB; `identity!==actor && cookie!=allow_token → 503`; **POST /login + /sso/saml/acs + /mfa fail-closed 503**. Alte `MaintenanceMiddleware` bleibt als dünner File-Flag-503 nur für Restore-Cutover. |
| QUIESCE | `OutboxWorker`/`reclaimStuck`/`ScheduledTaskRunner.tick` + `QuiesceService` | Pause-Gate am Schleifenanfang (DB + LISTEN `core_worker_pause`); `inFlight()` aggregiert processing+pending-faellig+reserved+XPENDING; Handshake `worker_heartbeats.state=paused`; **asynchron, GUI pollt** |
| BACKUP | `BackupService.createLocked()` | synchron im Controller; Verschluesselung erzwungen; STORES als Registry |
| ACTION | `ModuleLifecycle.installLocked()` etc. | innerhalb derselben Lock-Klammer; `critical_action.status` + heartbeat |
| VERIFY | neuer `ActionVerifier`-Contract pro Aktionstyp | module: install-State; secret rotate: Roundtrip-Probe; tenant_db_provision: Connect+Schema-Count |
| ROLLBACK | primär `ModuleLifecycle.rollbackInstall`; Notnagel `restore()` | Pre-Rollback-Snapshot zuerst; bei Restore-Crash Flag HALTEN |
| EXIT | `MaintenanceService.release()` | bedingtes `UPDATE … WHERE actor_id=:me AND NOT EXISTS(nicht-terminale Aktion)`; Allow-Token entwerten; `pg_notify` |

### 4.4 Welche Aktionen werden so GUI-fähig
- **module install** (bereits async umgesetzt), **tenant_db_provision, trust rotate, secret
  rotate** → ja, mit aktionseigenem transaktionalem Rollback als Primärpfad.
- **backup restore** → bedingt, aber **Stufe 1 NICHT** (erst nach erprobtem Quiesce/Cutover).

## 5. Empfohlene Umsetzungsreihenfolge
1. DB-Leitzustand + ungecachter Reader + `pg_notify` ← **Phase 1 ✅**
2. Worker-Pause-Gate + `QuiesceService` ← **Phase 2 ✅**
3. `SelectiveMaintenanceMiddleware` + Allow-Token + Login/Cookie-Block ← **Phase 3 ✅** (+ GUI: `MaintenanceController` engage/release/status + Drain-Polling-View)
4. `critical_action`-State-Machine + Crash-Recovery-Sweep ← **Phase 4 ✅**
5. Pre-Action-Backup-Gate (`createLocked`, erzwungene Verschluesselung, Store-Registry) ← **Phase 5 ✅**
6. `ActionVerifier` pro Typ + aktionseigener Rollback ← **Phase 6 läuft** (Increment 1: Fundament + module install ✅; tenant/secret/trust folgen)
7. zuletzt globaler Restore / `backup restore`-GUI hinter dem reifen Cutover-Pfad

### Umsetzungsstand
- **Phase 1** (`core.maintenance_session` + `MaintenanceService`): ungecachter Reader,
  atomares `engage()` (Partial-Unique-Index), `release()`, `pg_notify('core_maintenance')`.
- **Phase 2** (Worker-Pause-Gate + Quiesce):
  - `core.worker_pause` (Single-Row-Control-Tabelle, ungecacht) + `core.worker_heartbeats.state`.
  - `WorkerPauseGate` (uncached `isPaused`/`requestPause`/`release`, `pg_notify('core_worker_pause')`,
    120s-Deadline-Stempel).
  - `QuiesceService.inFlight()` aggregiert 6 Quellen (outbox processing + due-pending,
    webhook delivering + due-pending, module_install_jobs queued/running, job_queue reserved)
    über `Db::privileged()` (webhook_deliveries ist RLS-geschützt); `status()` liefert
    `paused/in_flight/by_source/workers_*/deadline_seconds_remaining/drained/timed_out/done`.
  - `OutboxWorker::run()`: Pause-Gate als erste Schleifenanweisung (skippt reclaim/scheduler/
    install/processBatch/webhooks), LISTEN `core_worker_pause` (auch im Recovery-Branch),
    Inner-Drain-Re-Check, `WorkerHeartbeat::markState('paused'/'running')` als Handshake.
  - **Entscheidung Multi-Worker**: gemeinsames Flag (alle Worker lesen dieselbe `worker_pause`-Row,
    pausieren gemeinsam; Drain-Beweis ist `inFlight()==0`, der Handshake ist sekundär).
  - Adversariale Review (0 critical/high, 3 medium, 11 low, 2 nit) eingearbeitet:
    Startup-`markState('running')` (SIGTERM-while-paused-Reststate), `WebhookService::reclaimStuckDeliveries()`
    im Worker-Zyklus (Drain wird self-healing statt nur Timeout), `timed_out` aus direktem
    `now() >= deadline_at` statt gerundetem Countdown, Redis-XPENDING-Limit dokumentiert.
  - 18 Tests; volle Suite grün; phpstan + phpcs sauber.

- **Phase 3** (Selektives Gate + Allow-Token + GUI):
  - `SelectiveMaintenanceMiddleware` (nach `AuthenticationMiddleware`): bei offener Session 503
    für alle ausser dem Operator (`identity===actor_user_id` ODER `hash_equals`-Allow-Token-Cookie);
    ungecacht, **fail-closed** bei DB-Fehler; allow-listet **exakt** `/health` + `/health/ready`
    (LB-Proben) — `/health/detail` + `/metrics` bleiben hinter dem Gate. Blockt damit auch
    POST /login + SSO + MFA (Post-Auth-Reject, Decision #2).
  - `AllowTokenCookie` (`maint_allow`): 256-bit-Token, nur SHA-256 persistiert, HttpOnly +
    SameSite=Lax + Secure-env-gated, Path `/` (für den Login-Block).
  - `MaintenanceController` (`system_maintenance`-Area): engage (atomar → Cookie → pause),
    release (resume → close → Cookie löschen), status-JSON; Audit `maintenance.engage/release`.
  - GUI: Drain-Polling-Banner (`templates/Admin/Maintenance/index.php`) + NAV-Eintrag + i18n.
  - Security-Review (0 critical/high, 1 medium, 7 low, 4 nit) eingearbeitet: Allow-Liste von
    Prefix auf exakte LB-Proben verengt + vor den Fail-closed-Branch gezogen; Fail-closed-Login,
    Non-Actor-e2e- und Cookie-Hardening-Tests ergänzt.
  - **Offen (deferred)**: `SESSION_COOKIE_SECURE`-Default in Prod fail-safe machen (plattformweit,
    nicht nur `maint_allow`); `release()` „exit only when stable"-Gate kommt mit Phase 4
    (heute jederzeit freigebbar); engage/release-Audit nutzt aktuell den Operator-Tenant-Scope
    (kein System-Tenant-Override im `AuditLogger`).

- **Phase 4** (Critical-Action-State-Machine + Crash-Recovery):
  - `core.critical_action` (9 States: quiescing/backing_up/running/verifying/rolling_back =
    non-terminal; succeeded/failed/aborted/needs_manual_restore = terminal) + Partial-Index
    über Non-Terminal + `fence_token` (reserviert für Phase-5/6-Fencing).
  - `CriticalActionService`: `start`/`transition`/`markSucceeded`/`markFailed`/`heartbeat`
    (Terminal-Writes sind guarded → kein Zombie-Overwrite), `hasNonTerminal`/`nonTerminalCount`
    (optional session-scoped), `recoverStale` (pre-mutation→aborted, mutating/post→
    needs_manual_restore; Message wird erhalten + angehängt).
  - **Exit-Gate atomar** (§4.3 EXIT): `MaintenanceService::releaseIfStable($sessionId)` schließt
    die Session in EINEM bedingten UPDATE nur, wenn keine non-terminale Aktion DIESER Session
    läuft (kein read-then-act); bei Verweigerung bleiben die Worker pausiert. Vorab `recoverStale`,
    damit ein toter Prozess den Exit nie deadlockt.
  - Crash-Recovery-Sweep: OutboxWorker-Startup + throttled im Loop (headless-Backstop) **und**
    Web-Read-Time (index/status).
  - Security-Review (0 critical/high, 2 medium, 6 low, 1 nit) eingearbeitet: atomares
    session-scoped Exit-Gate, Terminal-Write-Guard, Worker-Recovery- + Boundary-/Heartbeat-/
    Message-Tests ergänzt.
  - **Offen (Phase 5/6)**: echte Aktionen (module install, tenant provision, secret/trust rotate)
    mit `start()…markSucceeded()` umklammern; `fence_token`-Enforcement + `start()`-seitiger
    Refuse-when-closing (volle TOCTOU-Schließung); `needs_manual_restore`-Operator-Quittung-UI.

- **Phase 5** (Pre-Action-Backup-Gate):
  - `BackupService::createLocked()`: erzwingt Verschlüsselung + garantiert Probe-Restore — beides
    als **Post-Condition auf dem erzeugten Artefakt** (nicht als Pre-Check, der durch die
    unabhängige zweite `password()`-Lesung in `create()` umgangen werden könnte); bei Fehler wird
    das Backup verworfen (`delete`). Immer-Probe statt Setting-Inferenz (kein Coupling).
  - `CriticalActionService::backupGate()`: §4.2-BACKUP-Phase (`transition('backing_up')` mit
    Bewegt-Prüfung → `createLocked()` → `attachBackup()`); bei Fehler bleibt die Aktion
    `backing_up` (pre-mutation → Sweep abortet sauber). `transition()` liefert nun bool.
  - Security-Review (0 critical/high, 3 medium, 6 low) eingearbeitet: Verschlüsselungs- und
    Verifikations-Garantie als Artefakt-Invariante, Verwerfen verwaister Backups bei Probe-Fail,
    `backing_up`-Recoverable- und verify_on_create-ON-Tests ergänzt.
  - **Offen (Follow-up)**: `backups.verified` vermengt Integritäts- mit Restore-Verifikation
    (vorbestehend, UI-Label); `STORES als Registry` (modul-beigesteuerte Stores) — heute deckt der
    fixe STORES-Satz Modul-Code (modules/) + Modul-Daten (DB-Dump) ab.

- **Phase 6 — Increment 1** (Fundament + module install als Referenz-Aktion):
  - `CriticalActionHandler`-Contract (execute/verify/rollback) + Core-interne
    `CriticalActionRegistry`; `CriticalActionRunner` treibt eine eingereihte Aktion durch
    claim → Pflicht-Backup → execute → verify → succeed, mit Rollback (aktionseigener Undo)
    bei execute/verify-Fehler und Eskalation zu `needs_manual_restore`, wenn der Undo scheitert.
  - **Worker führt die Aktion während der Pause aus**: das Phase-2-Pause-Gate ruft
    `CriticalActionRunner::tick()` — verarbeitet die engagte Session-Aktion **erst** wenn der
    Platform gedrained ist (`inFlight()==0`); normale Arbeit bleibt pausiert.
  - **fence_token-Enforcement**: jede Transition/heartbeat trägt das Token; `recoverStale`
    rotiert es, sodass ein wiederbelebter Stale-Prozess die Aktion nicht mehr fortschreiben kann.
    Großzügiges Recovery-Fenster (`STALE_SECONDS=900`) für lange Phasen (Backup/Migrationen).
  - module install: `ModuleInstallHandler` (execute=`installPackage`, verify=modules-Row+Schema,
    rollback=`ModuleLifecycle::purge`→rollbackInstall); GUI reiht eine geschützte Installation
    während Maintenance ein (`critical_action.payload`).
  - 15 Tests (Runner-Orchestrierung mit Stub-Handler, Fence-Rotation, enqueue/claim, Verifier).
  - **Increment 2–4 offen**: tenant provision, secret rotate (+ Audit), trust rotate
    (transaktional wrappen) als eigene Handler.

#### Offene Review-Punkte (bewusst nach Phase 3 / Follow-up deferred)
- **Quiesce-aware Health**: ein pausierter Worker skippt `ScheduledTaskRunner.tick()`, daher können
  `sched:*`-Heartbeats während einer (≤120s) Quiesce kurz `stale→degraded` zeigen → `checkWorkers`
  während aktiver Pause nicht herabstufen (besser zusammen mit der Phase-3-Health/Maintenance-GUI).
- **job_queue-Reclaim**: `webhook_deliveries` hat jetzt einen Reclaim-Sweep; das generische
  `job_queue` (DB-Treiber) noch nicht — heute kein Produzent (dormant), bei Anbindung nachziehen.
- **Zusatztests (Hardening)**: Inner-Drain-Pause-Break (braucht Gate-Injection-Seam),
  SIGTERM-im-Pause-Wait, LISTEN-`core_worker_pause`-Nudge, Health-`paused`-Sichtbarkeit via `report()`.

## 6. Fixierte Entscheidungen
1. Leitzustand DB-ungecacht + `pg_notify`, Datei-Flag nur Restore-Cutover. **JA**
2. Mass-Logout: Post-Auth-Reject Pflicht; `core.sessions.user_id` optionaler Komfort. **REJECT**
3. Aktivierer-Identität: server-ausgestelltes Allow-Token-Cookie (+ `actor_id` Fallback). **TOKEN-COOKIE**
4. „Stabil" = „keine nicht-terminale Aktion". **SO**
5. Rollback: aktionseigener transaktionaler Undo primär; globaler Restore nur Notnagel. **SO**
6. Backup-Recht: dediziertes `system.maintenance` + erzwungen verschluesseltes Backup. **SO**
7. `backup restore` in Stufe 1 GUI-fähig? **NEIN**
8. Quiesce-Wait asynchron mit GUI-Polling + harte Deadline. **ASYNC**
9. Notausstieg: CLI-Break-Glass + TTL/Reassignment first-class. **JA**

## 7. Relevante Code-Anker
`core/src/Middleware/MaintenanceMiddleware.php`, `core/src/Middleware/SessionGuardMiddleware.php`
(Gate-Vorlage), `core/src/Service/System/MaintenanceMode.php`, `core/src/Service/Event/OutboxWorker.php`
(Pause-Gate + reclaimStuck), `core/src/Service/Schedule/ScheduledTaskRunner.php`,
`core/src/Service/Backup/BackupService.php` (createLocked, restore-Flag-Halten),
`core/src/Service/Auth/LocalAuthProvider.php` (Remember-Me), `core/src/Application.php`
(Middleware-Order), `core/config/Migrations/20260608110000_CoreSessions.php` (user_id),
`core/src/Service/Settings/SettingsManager.php` (ungecachter Maintenance-Reader).
