<?php
declare(strict_types=1);

namespace App\Service\I18n;

use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Version gate & resolution for language packs in the store (i18n-5, E39).
 *
 * Selects, per (component, locale), the matching pack version against the
 * active component version:
 *   - exactly equal         → used, status `clean`
 *   - same major, different → used, status `notice` (highest same-major)
 *   - different major / none → not used → English fallback, status `error`
 *
 * "Available" languages = locales for which the core has a usable catalog
 * (shipped core catalogs in resources/locales + usable core packs in the
 * store). English is always available.
 */
class LocaleResolver
{
    private function conn()
    {
        return ConnectionManager::get('default');
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }

    /**
     * Best pack version for (component, locale) against the active version.
     *
     * @return array{version: string, status: string}|null  null = no usable
     *   pack (major mismatch or none) → caller falls back to English.
     */
    public function resolveVersion(string $componentKey, string $activeVersion, string $locale): ?array
    {
        try {
            $rows = $this->conn()->execute(
                'SELECT version FROM language_packs WHERE component_key = :k AND locale = :l',
                ['k' => $componentKey, 'l' => $locale],
            )->fetchAll('assoc');
        } catch (Throwable) {
            return null;
        }
        $versions = array_map(static fn ($r) => (string)$r['version'], $rows);
        if ($versions === []) {
            return null;
        }
        if (in_array($activeVersion, $versions, true)) {
            return ['version' => $activeVersion, 'status' => 'clean'];
        }
        $activeMajor = self::major($activeVersion);
        $sameMajor = array_values(array_filter($versions, static fn ($v) => self::major($v) === $activeMajor));
        if ($sameMajor !== []) {
            usort($sameMajor, static fn ($a, $b) => version_compare($b, $a));

            return ['version' => $sameMajor[0], 'status' => 'notice'];
        }

        return null;
    }

    /**
     * Status per stored pack of a component against the active version
     * (for management/health). clean | notice | error.
     *
     * @return list<array{locale: string, version: string, status: string}>
     */
    public function packStatuses(string $componentKey, string $activeVersion): array
    {
        $rows = $this->conn()->execute(
            'SELECT locale, version FROM language_packs WHERE component_key = :k ORDER BY locale, version',
            ['k' => $componentKey],
        )->fetchAll('assoc');
        $activeMajor = self::major($activeVersion);
        $out = [];
        foreach ($rows as $r) {
            $v = (string)$r['version'];
            $status = $v === $activeVersion ? 'clean' : (self::major($v) === $activeMajor ? 'notice' : 'error');
            $out[] = ['locale' => (string)$r['locale'], 'version' => $v, 'status' => $status];
        }

        return $out;
    }

    /**
     * Locales offered (available) by the core: shipped core catalogs
     * (resources/locales) + usable core packs in the store. English always
     * included.
     *
     * @return list<string>
     */
    public function availableLocales(string $coreVersion): array
    {
        $locales = ['en_US'];

        // Shipped core catalogs.
        $shipped = defined('RESOURCES') ? RESOURCES . 'locales' : null;
        if ($shipped !== null && is_dir($shipped)) {
            foreach (glob($shipped . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $locales[] = basename($d);
            }
        }

        // Usable core packs from the store.
        try {
            $rows = $this->conn()->execute(
                "SELECT DISTINCT locale FROM language_packs WHERE component_key = 'core'",
            )->fetchAll('assoc');
            foreach ($rows as $r) {
                if ($this->resolveVersion('core', $coreVersion, (string)$r['locale']) !== null) {
                    $locales[] = (string)$r['locale'];
                }
            }
        } catch (Throwable) {
            // ignore
        }

        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales;
    }

    /**
     * Selectable locales for the switcher: enabled (`locale.enabled`) ∩
     * available (the core has a usable file). English always included.
     *
     * @param list<string> $enabled
     * @return list<string>
     */
    public function selectableLocales(array $enabled, string $coreVersion): array
    {
        $available = $this->availableLocales($coreVersion);
        $sel = array_values(array_filter($enabled, static fn ($l) => in_array($l, $available, true)));
        if (!in_array('en_US', $sel, true)) {
            array_unshift($sel, 'en_US');
        }

        return array_values(array_unique($sel));
    }

    /** Display name of a locale (fallback: the code itself). */
    public static function displayName(string $locale): string
    {
        return [
            'en_US' => 'English',
            'de_DE' => 'Deutsch',
            'fr_FR' => 'Français',
            'es_ES' => 'Español',
            'it_IT' => 'Italiano',
            'nl_NL' => 'Nederlands',
            'pt_PT' => 'Português',
            'pl_PL' => 'Polski',
        ][$locale] ?? $locale;
    }
}
