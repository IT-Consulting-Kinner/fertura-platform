<?php
declare(strict_types=1);

namespace App\Infrastructure;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Throwable;

use function Cake\Core\env;

/**
 * Verbindungs-Auswahl für privilegierte Pfade (RLS-Wirksamkeit, Entscheidung E26).
 *
 * Die Default-Connection läuft im Betrieb als **NOBYPASSRLS-Rolle** (fertura_app),
 * damit Row-Level-Security zur Laufzeit greift. DDL-/Wartungs-/Bypass-Pfade
 * (Modul-Lifecycle, Modul-Migrationen, Update-Manager, Recovery, Worker) nutzen
 * die **privilegierte** Connection (Superuser), die RLS umgeht.
 *
 * Fällt auf 'default' zurück, wenn keine privilegierte Connection konfiguriert
 * ist (z. B. Dev ohne getrennte Rollen) -> abwärtskompatibel.
 */
class Db
{
    public static function privileged(): Connection
    {
        // Die privilegierte Connection ist genau dann nutzbar, wenn ein
        // Superuser-DSN konfiguriert ist (DATABASE_URL). Hinweis: CakePHP parst
        // 'url' beim Registrieren in Einzelschlüssel um, daher hier env() statt
        // getConfig()['url'].
        if (env('DATABASE_URL') && ConnectionManager::getConfig('privileged') !== null) {
            try {
                /** @var Connection */
                return ConnectionManager::get('privileged');
            } catch (Throwable) {
                // fällt unten auf default zurück
            }
        }

        /** @var Connection */
        return ConnectionManager::get('default');
    }

    /**
     * Name der privilegierten Connection (für APIs, die einen Connection-Namen
     * erwarten, z. B. cakephp/migrations). Fällt auf 'default' zurück.
     */
    public static function privilegedName(): string
    {
        if (env('DATABASE_URL') && ConnectionManager::getConfig('privileged') !== null) {
            return 'privileged';
        }

        return 'default';
    }
}
