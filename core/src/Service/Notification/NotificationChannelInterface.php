<?php
declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Contract for a notification channel provided by a module
 * (Program Tier-1, P09; core contract `core.collector.notification_channel`).
 *
 * Modules register the contribution in the manifest (`collectors_registered`)
 * and implement this interface (e.g. Slack/Teams/SMS). The core invokes
 * `deliver()` in-process via the {@see \App\Service\Module\ContributionRuntime}.
 */
interface NotificationChannelInterface
{
    /** Unique channel key (e.g. `slack`); used for preferences. */
    public function key(): string;

    /**
     * @param array{user_id:string,type:string,title:string,body:string,data:array<string,mixed>,email:?string} $notification
     */
    public function deliver(array $notification): void;
}
