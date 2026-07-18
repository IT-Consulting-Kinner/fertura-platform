<?php
declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Service\Module\ContributionRuntime;
use App\Utility\AppUrl;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\I18n;
use Cake\Log\Log;
use Throwable;

/**
 * Gathers the admin-dashboard tiles each enabled module contributes via the
 * `core.collector.dashboard_tiles` collector (Paket 4), coerces every tile to
 * safe data, and groups them by owning module for the accordion display.
 *
 * The tile shape is module-provided (untrusted): labels/values are coerced to
 * strings (the view escapes them), URLs are kept only when app-relative
 * ({@see AppUrl}), and the variant is allowlisted. A provider that throws is
 * skipped (its group is simply absent) — one module must not break the dashboard.
 */
class DashboardTileCollector
{
    private const CONTRACT = 'core.collector.dashboard_tiles';

    private const VARIANTS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

    /**
     * Defensive caps on the dashboard hot path: a misbehaving (but in-process,
     * trusted-at-install) provider that returns a flood of tiles or a multi-MB
     * label must not blow up the page. Modules are trusted; this only bounds an
     * accident, not a threat.
     */
    private const MAX_TILES_PER_MODULE = 12;
    private const MAX_LABEL_LEN = 120;
    private const MAX_VALUE_LEN = 40;

    /**
     * One group per contributing module: `{key, name, tiles}`; empty when no
     * module contributes (module-free operator tenant, or none enabled).
     *
     * @param array{is_operator: bool} $context
     * @return list<array{key: string, name: string, tiles: list<array<string, mixed>>}>
     */
    public function collect(array $context): array
    {
        $runtime = new ContributionRuntime();
        // Tenant-scoped: only modules enabled for the viewing tenant contribute
        // (and none for the module-free operator tenant in multi_org).
        $collectors = $runtime->collectors(self::CONTRACT);
        if ($collectors === []) {
            return [];
        }

        $call = ['locale' => I18n::getLocale(), 'is_operator' => (bool)$context['is_operator']];
        $names = $this->moduleNames();

        // Accumulate tiles per module (a module may register more than one provider).
        $byModule = [];
        foreach ($collectors as $contrib) {
            $moduleKey = (string)$contrib['module_key'];
            try {
                $raw = (array)$runtime->call($contrib, 'dashboardTiles', [$call]);
            } catch (Throwable $e) {
                Log::error('Dashboard-Kachel-Provider fehlgeschlagen: ' . $e->getMessage(), ['module' => $moduleKey]);

                continue;
            }
            foreach ($raw as $tile) {
                if (count($byModule[$moduleKey] ?? []) >= self::MAX_TILES_PER_MODULE) {
                    break; // cap: ignore a flood of tiles from one provider
                }
                $coerced = is_array($tile) ? $this->coerceTile($tile) : null;
                if ($coerced !== null) {
                    $byModule[$moduleKey][] = $coerced;
                }
            }
        }

        // Every $byModule entry holds at least one coerced tile (only non-null
        // tiles were appended), so each becomes a group.
        $groups = [];
        foreach ($byModule as $moduleKey => $tiles) {
            $groups[] = [
                'key' => $moduleKey,
                'name' => $names[$moduleKey] ?? $moduleKey,
                'tiles' => $tiles,
            ];
        }

        return $groups;
    }

    /**
     * Coerces one module-provided tile to safe data, or null when it carries no
     * label (a tile with nothing to show).
     *
     * @param array<string, mixed> $tile
     * @return array<string, mixed>|null
     */
    private function coerceTile(array $tile): ?array
    {
        $label = isset($tile['label']) && is_scalar($tile['label']) ? (string)$tile['label'] : '';
        if ($label === '') {
            return null;
        }
        $value = isset($tile['value']) && is_scalar($tile['value']) ? (string)$tile['value'] : '';
        $out = [
            // Truncate defensively (multibyte-safe) so an oversized string can't
            // bloat the page; the view escapes the result.
            'label' => mb_substr($label, 0, self::MAX_LABEL_LEN),
            'value' => mb_substr($value, 0, self::MAX_VALUE_LEN),
        ];
        if (isset($tile['url']) && is_string($tile['url']) && AppUrl::isSafeRelative($tile['url'])) {
            $out['url'] = $tile['url'];
        }
        if (isset($tile['variant']) && in_array($tile['variant'], self::VARIANTS, true)) {
            $out['variant'] = (string)$tile['variant'];
        }

        return $out;
    }

    /**
     * Module key => display name, for the accordion group headings.
     *
     * @return array<string, string>
     */
    private function moduleNames(): array
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        $out = [];
        foreach ($conn->execute('SELECT module_key, name FROM modules')->fetchAll('assoc') as $row) {
            $out[(string)$row['module_key']] = (string)$row['name'];
        }

        return $out;
    }
}
