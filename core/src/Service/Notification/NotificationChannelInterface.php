<?php
declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Vertrag für einen von einem Modul bereitgestellten Benachrichtigungskanal
 * (Programm Tier-1, P09; Core-Contract `core.collector.notification_channel`).
 *
 * Module registrieren den Beitrag im Manifest (`collectors_registered`) und
 * implementieren dieses Interface (z. B. Slack/Teams/SMS). Der Core ruft
 * `deliver()` über die {@see \App\Service\Module\ContributionRuntime} auf —
 * in-process oder (out_of_process) über RPC.
 */
interface NotificationChannelInterface
{
    /** Eindeutiger Kanal-Schlüssel (z. B. `slack`); für Präferenzen verwendet. */
    public function key(): string;

    /**
     * @param array{user_id:string,type:string,title:string,body:string,data:array<string,mixed>,email:?string} $notification
     */
    public function deliver(array $notification): void;
}
