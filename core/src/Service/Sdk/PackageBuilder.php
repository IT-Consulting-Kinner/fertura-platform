<?php
declare(strict_types=1);

namespace App\Service\Sdk;

use App\Service\Registry\SemVer;

/**
 * Release-assembly pre-step for module packages (E176, ch. 24.13.2 / 28.9 /
 * 28.14.2) — the missing step between "module edited" and "package signed".
 *
 * Turns a module SOURCE directory into a clean, signable package directory plus a
 * `release.json`, after enforcing the release gates the spec requires BEFORE
 * signing:
 *  - manifest/structure lint (P16, {@see ManifestLinter}),
 *  - a valid, monotonically increasing `version` (28.9.2),
 *  - every migration ships a reversible `-- @DOWN` (28.14.2 "reversible
 *    migrations" — the precondition the Core relies on for rollback),
 *  - a CHANGELOG entry for the version (shown by the update flow, 24.13/28.9.1).
 *
 * The output directory is exactly what `bin/cake mp_tool sign` then signs; because
 * `release.json` lives inside it, the changelog + reversibility attestation are
 * covered by the package signature (tamper-evident). Dev-only paths (tests, VCS,
 * lint/CI config, vendor) are never shipped.
 */
final class PackageBuilder
{
    /** Dev-only entries never shipped in a package. */
    private const EXCLUDE = [
        '.git', '.github', 'tests', 'node_modules', 'vendor',
        'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan-baseline.neon',
        'phpcs.xml', 'phpcs.xml.dist', '.gitignore', 'signature.json', 'release.json',
    ];

    private const SEMVER = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)*$/';

    /**
     * @return array{ok:bool, errors:list<string>, path?:string, release?:array<string,mixed>}
     */
    public function build(string $moduleDir, string $outDir, ?string $previousVersion = null): array
    {
        $moduleDir = rtrim($moduleDir, '/\\');
        $manifestPath = $moduleDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            return ['ok' => false, 'errors' => ["manifest.json fehlt in $moduleDir"]];
        }
        /** @var array<string,mixed>|null $manifest */
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'errors' => ['manifest.json ist kein gültiges JSON-Objekt']];
        }

        $errors = [];

        // 1) Manifest/structure lint (P16).
        foreach ((new ManifestLinter())->lintFile($manifestPath)['errors'] as $e) {
            $errors[] = "Lint: $e";
        }

        // 2) Version: valid SemVer, monotonic over the previous release (28.9.2).
        $key = (string)($manifest['id'] ?? '');
        $version = (string)($manifest['version'] ?? '');
        if (preg_match(self::SEMVER, $version) !== 1) {
            $errors[] = "version ist kein gültiges SemVer: '$version'";
        } elseif ($previousVersion !== null) {
            if (preg_match(self::SEMVER, $previousVersion) !== 1) {
                $errors[] = "--previous ist kein gültiges SemVer: '$previousVersion'";
            } elseif (SemVer::parse($version)->compare(SemVer::parse($previousVersion)) <= 0) {
                $errors[] = "version $version muss > der Vorversion $previousVersion sein (Monotonie, 28.9.2)";
            }
        }

        // 3) Reversibility (28.14.2): every migration ships a non-empty `-- @DOWN`.
        $migrations = [];
        $migDir = $moduleDir . '/migrations';
        if (is_dir($migDir)) {
            $files = glob($migDir . '/*.sql') ?: [];
            sort($files);
            foreach ($files as $f) {
                $reversible = $this->hasDownSection((string)file_get_contents($f));
                if (!$reversible) {
                    $errors[] = 'Migration ohne umkehrende @DOWN-Operation: ' . basename($f) . ' (28.14.2)';
                }
                $migrations[] = ['file' => basename($f), 'reversible' => $reversible];
            }
        }

        // 4) Changelog entry for the version (shown at update, 24.13/28.9.1).
        $changelog = $this->changelogFor($moduleDir, $version);
        if ($changelog === null) {
            $errors[] = "CHANGELOG.md fehlt oder hat keinen Eintrag für Version $version";
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 5) Assemble the clean, signable staging directory.
        $stage = rtrim($outDir, '/\\') . '/' . $key . '-' . $version;
        $this->rrmdir($stage);
        $this->copyTree($moduleDir, $stage);

        // 6) release.json (inside the dir -> covered by the package signature).
        $release = [
            'format' => '1.0',
            'module_key' => $key,
            'version' => $version,
            'core_compatibility' => (string)($manifest['core_compatibility'] ?? ''),
            'migrations' => $migrations,
            'changelog' => (string)$changelog,
        ];
        file_put_contents(
            $stage . '/release.json',
            json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );

        return ['ok' => true, 'errors' => [], 'path' => $stage, 'release' => $release];
    }

    /** True if the migration declares a `-- @DOWN` marker with a non-empty body. */
    private function hasDownSection(string $sql): bool
    {
        $pos = stripos($sql, '-- @DOWN');

        return $pos !== false && trim(substr($sql, $pos + strlen('-- @DOWN'))) !== '';
    }

    /** The changelog body for $version (heading containing the version), or null. */
    private function changelogFor(string $moduleDir, string $version): ?string
    {
        $path = $moduleDir . '/CHANGELOG.md';
        if (!is_file($path)) {
            return null;
        }
        $body = [];
        $inSection = false;
        foreach (preg_split('/\R/', (string)file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                if ($inSection) {
                    break; // the next heading ends the version's section
                }
                // A heading containing the version as a whole token starts it.
                $inSection = preg_match('/(?<![0-9.])' . preg_quote($version, '/') . '(?![0-9.])/', $line) === 1;
                continue;
            }
            if ($inSection) {
                $body[] = $line;
            }
        }

        return $inSection ? trim(implode("\n", $body)) : null;
    }

    private function copyTree(string $src, string $dst): void
    {
        mkdir($dst, 0o775, true);
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..' || in_array($item, self::EXCLUDE, true)) {
                continue;
            }
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            is_dir($s) ? $this->copyTree($s, $d) : copy($s, $d);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
