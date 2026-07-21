<?php
declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Uniform "Zusatzinformation" for the tile-based admin navigation
 * ({@see \App\Controller\Admin\NavController}): every tile that stands for a
 * COLLECTION of elements gets the same shape — a headline `badge` (the primary
 * count) plus a descriptive `detail` sub-line — so tiles no longer diverge (one
 * showed "x aktiv", the next nothing).
 *
 * The default shape for a collection of active/inactive elements (users, groups,
 * tenants, modules, contracts, tenant modules, backup plans, trust anchors, …) is
 * "x aktiv / y inaktiv". Tiles that are NOT such a
 * collection — a queue (outbox: pending events) or a service-owned validity
 * concept (licenses) — use {@see self::single()} with their own label so they
 * still carry a consistent, non-empty detail without misrepresenting an
 * active/inactive split they do not have.
 */
final class TileMetrics
{
    /**
     * A collection split into active vs. inactive elements: badge = active count,
     * detail = "x aktiv / y inaktiv".
     *
     * @return array{badge: string, detail: string}
     */
    public static function activeInactive(int $active, int $inactive): array
    {
        // Compose the figure + translated word in PHP (as the existing user-status
        // detail does), NOT via i18n placeholders — the app's translator does not
        // reliably substitute %s/{0} at render time.
        $inactive = max(0, $inactive);

        return [
            'badge' => (string)$active,
            'detail' => $active . ' ' . __('admin.metric.active')
                . ' / ' . $inactive . ' ' . __('admin.metric.inactive'),
        ];
    }

    /**
     * A single-figure tile (not an active/inactive collection): badge = the count,
     * detail = the given label resolved with the count (e.g. "x ausstehend",
     * "x insgesamt").
     *
     * @return array{badge: string, detail: string}
     */
    public static function single(int $count, string $labelKey): array
    {
        return [
            'badge' => (string)$count,
            'detail' => $count . ' ' . __($labelKey),
        ];
    }
}
