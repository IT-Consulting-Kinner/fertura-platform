<?php
declare(strict_types=1);

namespace App\Model;

/**
 * Request-/prozessweiter Kontext des handelnden Benutzers (Akteur) für
 * Footprint-Spalten (created_by/updated_by, Entscheidung E8).
 *
 * Wird pro HTTP-Request von der FootprintMiddleware aus der Identität gesetzt
 * und danach geleert. In CLI-/Systemkontexten ohne angemeldeten Benutzer bleibt
 * der Akteur null (z. B. Bootstrap-Admin via Konsole).
 */
class ActorContext
{
    private static ?string $actorId = null;

    public static function set(?string $actorId): void
    {
        self::$actorId = $actorId;
    }

    public static function get(): ?string
    {
        return self::$actorId;
    }

    public static function clear(): void
    {
        self::$actorId = null;
    }
}
