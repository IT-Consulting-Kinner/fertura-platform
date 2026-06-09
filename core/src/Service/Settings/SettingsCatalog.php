<?php
declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Katalog der bekannten Core-Settings: sichere Vorgabewerte (greifen auch ohne
 * DB-Eintrag, Kap. 27.16.3 / Entscheidung 162), Typ- und Wertebereichs-
 * validierung sowie das Secret-Flag (verschlüsselte Ablage, Entscheidung 159).
 *
 * Definition je Schlüssel: type (int|bool|string|json), default, optional
 * min/max (für int) und secret (bool).
 */
class SettingsCatalog
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private const DEFINITIONS = [
        'core' => [
            // Passwort-Policy (in Step 2 als Code-Default, jetzt DB-konfigurierbar).
            'password.min_length' => ['type' => 'int', 'default' => 12, 'min' => 6, 'max' => 128],
            // Anmeldeschutz (Entscheidung 162).
            'login_throttle.max_attempts' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 1000],
            'login_throttle.window_minutes' => ['type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440],
            // Session-Timeout (Wiring an die Session folgt mit der GUI, Step 10).
            'session.timeout_minutes' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 43200],
            // Beispiel für ein verschlüsseltes Geheimnis.
            'smtp.password' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Mehrsprachigkeit (i18n, E37): Standardsprache + angebotene Sprachen.
            'locale.default' => ['type' => 'string', 'default' => 'en_US'],
            'locale.enabled' => ['type' => 'json', 'default' => ['en_US', 'de_DE']],
            // System-/Identitätsmails (Einladung, Passwort-Reset).
            'mail.enabled' => ['type' => 'bool', 'default' => true],
            'mail.from_address' => ['type' => 'string', 'default' => 'no-reply@fertura.local'],
            'mail.from_name' => ['type' => 'string', 'default' => 'Fertura'],
            // Marketplace / Signatur / Wartung (Step 8).
            'require_module_signature' => ['type' => 'bool', 'default' => true],
            'crl_max_age_days' => ['type' => 'int', 'default' => 7, 'min' => 0, 'max' => 365],
            // Maximales Alter der Online-Lizenzbestätigung (Kap. 28.7.3.1).
            'license.online_max_age_days' => ['type' => 'int', 'default' => 7, 'min' => 1, 'max' => 365],
            'marketplace.base_url' => ['type' => 'string', 'default' => null],
            'maintenance_mode' => ['type' => 'bool', 'default' => false],
            // Observability (Step 12, Kap. 20.2).
            'storage.path' => ['type' => 'string', 'default' => null],
            'health.worker_max_age_seconds' => ['type' => 'int', 'default' => 120, 'min' => 10, 'max' => 86400],
            'health_token' => ['type' => 'string', 'default' => null, 'secret' => true],

            // Daten-Backup (Kap. 20.1.2). Pfad als Container-/Linux- bzw.
            // Windows-Pfad; leer = Standard-Volume. Scheduler läuft im Worker.
            'backup.path' => ['type' => 'string', 'default' => null],
            'backup.schedule.enabled' => ['type' => 'bool', 'default' => false],
            'backup.schedule.interval_hours' => ['type' => 'int', 'default' => 24, 'min' => 1, 'max' => 8760],
            'backup.retention' => ['type' => 'int', 'default' => 14, 'min' => 1, 'max' => 3650],
            // Passwort verschlüsselt den Archivinhalt (AES-256). Secret: nie im
            // Klartext angezeigt. Leer = unverschlüsselt (Warnung).
            'backup.password' => ['type' => 'string', 'default' => null, 'secret' => true],
            // Vor dem Abschluss zusätzlich Probe-Restore in eine Scratch-DB fahren
            // (garantiert Wiederherstellbarkeit). Integritätsprüfung läuft immer.
            'backup.verify_on_create' => ['type' => 'bool', 'default' => true],
            // Aufbewahrung zusätzlich nach Alter (Tage); 0 = aus.
            'backup.retention_days' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 3650],
            // Mindestens freier Speicher am Zielort vor dem Backup (MB, Pre-Flight).
            'backup.min_free_mb' => ['type' => 'int', 'default' => 500, 'min' => 0, 'max' => 10485760],
            // Alarm-Empfänger bei fehlgeschlagenem Backup (E-Mail). Leer = aus.
            'backup.alert_email' => ['type' => 'string', 'default' => null],
            // Optionales Launcher-Prefix für isolierte Modul-Hosts (Kap. 23.16.2):
            // Befehl(+Argumente), der VOR `php` gesetzt wird, um den Host-Prozess
            // zusätzlich vom Betriebssystem zu isolieren — z. B.
            //   "setpriv --reuid=1001 --regid=1001 --clear-groups --"  (eigener OS-Benutzer),
            //   "bwrap --unshare-all --ro-bind / / --proc /proc --dev /dev --die-with-parent"  (FS/Kernel-Sandbox),
            //   "firejail --quiet --private".
            // Wird unverändert (NICHT als ein Argument gequotet) eingesetzt, läuft
            // also in der bereits bereinigten `env -i`-Umgebung und exec/wrapped `php`.
            // Leer = kein Prefix (Default, In-Process-UID). Der Befehl muss das Image
            // bereitstellen und Argumente an `php` durchreichen.
            // WICHTIG: Der Launcher muss `php` per **exec** ersetzen oder SIGTERM an
            //   den Host weiterreichen und mit dem Elternprozess sterben (z. B.
            //   `setpriv … --`, `bwrap … --die-with-parent`). Ein Launcher, der
            //   abspaltet und sich löst (z. B. `firejail` ohne entsprechende Optionen),
            //   kann beim Stoppen einen verwaisten Host hinterlassen.
            // SICHERHEIT: Wer dieses Setting setzen darf, kann beliebigen Code als
            //   Worker-Benutzer ausführen (Shell-Prefix) — auf dieselbe Vertrauensstufe
            //   wie Shell-Zugriff beschränken. Siehe MODULE_DEVELOPMENT / 23.16.2.
            'module.host.launcher' => ['type' => 'string', 'default' => null],
            // Gehärtetes Outbound-HTTP (P01): gemeinsamer Egress für Webhooks/OIDC/AI.
            'http.egress.enabled' => ['type' => 'bool', 'default' => true],
            'http.egress.timeout_seconds' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120],
            // Max. Antwortgröße in Bytes (0 = unbegrenzt); Default 5 MiB.
            'http.egress.max_response_bytes' => ['type' => 'int', 'default' => 5000000, 'min' => 0, 'max' => 104857600],
            // SSRF-Schutz aufheben (private/reservierte Ziele erlauben) — nur bewusst setzen.
            'http.egress.allow_private' => ['type' => 'bool', 'default' => false],
            // Hostnamen/IP-Literale, die trotz privater Auflösung erlaubt sind (interne Integrationen).
            'http.egress.allowlist' => ['type' => 'json', 'default' => []],
            'http.egress.user_agent' => ['type' => 'string', 'default' => 'Fertura/1.0 (+egress)'],
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $namespace, string $key): ?array
    {
        return self::DEFINITIONS[$namespace][$key] ?? null;
    }

    /**
     * Alle bekannten Definitionen (für die Verwaltungs-GUI, Step 10).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function all(): array
    {
        return self::DEFINITIONS;
    }

    public function default(string $namespace, string $key): mixed
    {
        return self::DEFINITIONS[$namespace][$key]['default'] ?? null;
    }

    public function isSecret(string $namespace, string $key): bool
    {
        return (bool)(self::DEFINITIONS[$namespace][$key]['secret'] ?? false);
    }

    public function isKnown(string $namespace, string $key): bool
    {
        return isset(self::DEFINITIONS[$namespace][$key]);
    }

    /**
     * Validiert einen Wert gegen die Katalogdefinition.
     *
     * @return list<string> Liste der Fehlermeldungen (leer = gültig).
     */
    public function validate(string $namespace, string $key, mixed $value): array
    {
        $def = $this->definition($namespace, $key);
        if ($def === null) {
            return ["Unbekanntes Setting: $namespace.$key"];
        }
        if ($value === null) {
            return [];
        }

        $errors = [];
        switch ($def['type']) {
            case 'int':
                if (!is_int($value)) {
                    $errors[] = "$key muss eine Ganzzahl sein.";
                    break;
                }
                if (isset($def['min']) && $value < $def['min']) {
                    $errors[] = "$key muss >= {$def['min']} sein.";
                }
                if (isset($def['max']) && $value > $def['max']) {
                    $errors[] = "$key muss <= {$def['max']} sein.";
                }
                break;
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = "$key muss ein Boolescher Wert sein.";
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $errors[] = "$key muss eine Zeichenkette sein.";
                }
                break;
            case 'json':
                if (!is_array($value)) {
                    $errors[] = "$key muss ein strukturierter Wert (Array/Objekt) sein.";
                }
                break;
        }

        return $errors;
    }
}
