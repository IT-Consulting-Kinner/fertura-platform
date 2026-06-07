<?php
declare(strict_types=1);

namespace App\Service\I18n;

use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Versions-Gate & Auflösung für Sprachpakete im Store (i18n-5, E39).
 *
 * Wählt je (Komponente, Locale) die passende Pack-Version gegen die aktive
 * Komponentenversion:
 *   - exakt gleich          → genutzt, Status `clean`
 *   - gleiche Major, andere → genutzt, Status `notice` (höchste Same-Major)
 *   - andere Major / keine  → nicht genutzt → Englisch-Fallback, Status `error`
 *
 * „Verfügbare" Sprachen = Locales, für die der Core einen nutzbaren Katalog hat
 * (mitgelieferte Core-Kataloge in resources/locales + nutzbare Core-Packs im
 * Store). Englisch ist immer verfügbar.
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
     * Beste Pack-Version für (Komponente, Locale) gegen die aktive Version.
     *
     * @return array{version: string, status: string}|null  null = kein nutzbares
     *   Pack (Major-Mismatch oder keines) → Aufrufer fällt auf Englisch zurück.
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
     * Status je gespeichertem Pack einer Komponente gegen die aktive Version
     * (für Verwaltung/Health). clean | notice | error.
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
     * Vom Core angebotene (verfügbare) Locales: mitgelieferte Core-Kataloge
     * (resources/locales) + nutzbare Core-Packs im Store. Englisch immer dabei.
     *
     * @return list<string>
     */
    public function availableLocales(string $coreVersion): array
    {
        $locales = ['en_US'];

        // Mitgelieferte Core-Kataloge.
        $shipped = defined('RESOURCES') ? RESOURCES . 'locales' : null;
        if ($shipped !== null && is_dir($shipped)) {
            foreach (glob($shipped . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $locales[] = basename($d);
            }
        }

        // Nutzbare Core-Packs aus dem Store.
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
}
