<?php
declare(strict_types=1);

namespace App\Model;

/**
 * Request-/process-wide context of the acting user (actor) for footprint
 * columns (created_by/updated_by, Decision E8).
 *
 * Set per HTTP request by the FootprintMiddleware from the identity, then
 * cleared afterwards. In CLI/system contexts without a logged-in user the actor
 * stays null (e.g. bootstrap admin via console).
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
