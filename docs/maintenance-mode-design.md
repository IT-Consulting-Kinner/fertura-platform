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
  `WHERE status='active'`** erzwingt genau eine offene Session (atomarer Eintritt via
  `INSERT … ON CONFLICT DO NOTHING`, Verlierer = 409).
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
1. DB-Leitzustand + ungecachter Reader + `pg_notify` ← **Phase 1**
2. Worker-Pause-Gate + `QuiesceService`
3. `SelectiveMaintenanceMiddleware` + Allow-Token + Login/Cookie-Block
4. `critical_action`-State-Machine + Crash-Recovery-Sweep
5. Pre-Action-Backup-Gate (`createLocked`, erzwungene Verschluesselung, Store-Registry)
6. `ActionVerifier` pro Typ + aktionseigener Rollback
7. zuletzt globaler Restore / `backup restore`-GUI hinter dem reifen Cutover-Pfad

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
