<?php
declare(strict_types=1);

namespace App\Service\I18n;

use App\Application;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Orchestriert die Sprachverwaltungs-GUI (i18n-6, E41/E42).
 *
 * Operiert auf den **Store**-Packs (Module + nachgeladene Core-/Extension-
 * Sprachen). Die mitgelieferten Core-Kataloge (resources/locales) werden in der
 * Übersicht read-only ausgewiesen (E43), aber nicht über den Editor verändert.
 *
 * Import per GUI = unsignierter `.po`-Upload (E42): `source=upload`,
 * `signed=false`, `reviewed=false` bis zum Review. Editieren setzt
 * `edited=true` und `reviewed=true` (Admin-Edit = Review, E38).
 */
class LanguagePackAdmin
{
    public function __construct(
        private ?LanguagePackStore $store = null,
        private ?LocaleResolver $resolver = null,
    ) {
        $this->store ??= new LanguagePackStore();
        $this->resolver ??= new LocaleResolver();
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    /**
     * Übersicht: aktive/inaktive Komponenten mit gruppierten Store-Packs +
     * berechnetem Versions-Status. Core zusätzlich mit mitgelieferten Locales.
     *
     * @return list<array<string,mixed>>
     */
    public function overview(): array
    {
        $rows = $this->conn()->execute(
            'SELECT lp.component_key, lp.component_type, lp.locale, lp.version, lp.domain, '
            . 'lp.signed, lp.reviewed, lp.edited, lp.source, '
            . 'm.status AS module_status, m.version AS module_version, m.name AS module_name '
            . 'FROM language_packs lp '
            . 'LEFT JOIN modules m ON m.module_key = lp.component_key '
            . 'ORDER BY lp.component_key, lp.locale, lp.version',
        )->fetchAll('assoc');

        $components = [];
        foreach ($rows as $r) {
            $key = (string)$r['component_key'];
            if (!isset($components[$key])) {
                [$active, $version, $name] = $this->componentMeta($r);
                $components[$key] = [
                    'key' => $key,
                    'type' => (string)$r['component_type'],
                    'name' => $name,
                    'active_version' => $version,
                    'active' => $active,
                    'shipped' => $key === 'core' ? $this->shippedCoreLocales() : [],
                    'packs' => [],
                ];
            }
            $activeVersion = $components[$key]['active_version'];
            $status = $this->packStatus((string)$r['version'], $activeVersion);
            $components[$key]['packs'][] = [
                'locale' => (string)$r['locale'],
                'version' => (string)$r['version'],
                'domain' => (string)$r['domain'],
                'status' => $status,
                'signed' => $this->bool($r['signed']),
                'reviewed' => $this->bool($r['reviewed']),
                'edited' => $this->bool($r['edited']),
                'source' => (string)$r['source'],
                'editable' => true,
                'deletable' => $this->mayDelete($components[$key]['active'], (string)$r['locale']),
            ];
        }

        return array_values($components);
    }

    /** @param array<string,mixed> $r @return array{0:bool,1:string,2:string} */
    private function componentMeta(array $r): array
    {
        $key = (string)$r['component_key'];
        if ($key === 'core') {
            return [true, Application::CORE_VERSION, 'Core'];
        }
        if ($r['module_status'] === null) {
            // Komponente entfernt, Dateien verblieben (E41): inaktiv.
            return [false, (string)$r['version'], $key];
        }

        return [
            $r['module_status'] === 'active',
            (string)$r['module_version'],
            (string)($r['module_name'] ?? $key),
        ];
    }

    private function packStatus(string $packVersion, string $activeVersion): string
    {
        if ($packVersion === $activeVersion) {
            return 'clean';
        }

        return explode('.', $packVersion)[0] === explode('.', $activeVersion)[0] ? 'notice' : 'error';
    }

    /** Englisch darf bei aktiver Komponente nicht gelöscht werden (E41). */
    public function mayDelete(bool $componentActive, string $locale): bool
    {
        if (!$componentActive) {
            return true;
        }

        return $locale !== 'en_US';
    }

    /** @return list<string> */
    private function shippedCoreLocales(): array
    {
        $out = [];
        $dir = defined('RESOURCES') ? RESOURCES . 'locales' : null;
        if ($dir !== null && is_dir($dir)) {
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                $out[] = basename($d);
            }
        }
        sort($out);

        return $out;
    }

    private function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    /** @return array<string,mixed>|null */
    public function meta(string $componentKey, string $version, string $locale): ?array
    {
        $row = $this->conn()->execute(
            'SELECT component_type, component_key, locale, version, domain, signed, reviewed, edited, source, uploaded_by '
            . 'FROM language_packs WHERE component_key = :k AND version = :v AND locale = :l',
            ['k' => $componentKey, 'v' => $version, 'l' => $locale],
        )->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * Editierbare Einträge eines Packs.
     *
     * @return list<array{index:int,ctx:?string,id:string,plural:?string,msgstr:list<string>,comments:list<string>}>
     */
    public function entries(string $componentKey, string $version, string $locale, string $domain): array
    {
        $content = $this->store->read($componentKey, $version, $locale, $domain);
        if ($content === null) {
            throw new RuntimeException('Sprachdatei nicht gefunden.');
        }

        return PoDocument::parse($content)->editableEntries();
    }

    /**
     * Speichert geänderte Übersetzungen (msgstr je Eintrags-Index) verlustfrei.
     * Setzt edited=true, reviewed=true (Admin-Edit = Review, E38).
     *
     * @param array<int, list<string>> $msgstrByIndex
     */
    public function saveEntries(string $componentKey, string $version, string $locale, string $domain, array $msgstrByIndex, ?string $actorId): void
    {
        $meta = $this->meta($componentKey, $version, $locale);
        if ($meta === null) {
            throw new RuntimeException('Sprachpaket-Metadaten fehlen.');
        }
        $content = $this->store->read($componentKey, $version, $locale, $domain);
        if ($content === null) {
            throw new RuntimeException('Sprachdatei nicht gefunden.');
        }
        $doc = PoDocument::parse($content);
        foreach ($msgstrByIndex as $idx => $values) {
            $doc->setMsgstr((int)$idx, $values);
        }
        $this->store->save($componentKey, $version, $locale, $doc->serialize(), [
            'type' => (string)$meta['component_type'],
            'domain' => $domain,
            'signed' => $this->bool($meta['signed']),
            'reviewed' => true,
            'edited' => true,
            'source' => (string)$meta['source'],
            'uploadedBy' => $meta['uploaded_by'] !== null ? (string)$meta['uploaded_by'] : $actorId,
        ]);
    }

    /** Setzt reviewed=true ohne inhaltliche Änderung. */
    public function review(string $componentKey, string $version, string $locale, string $domain): void
    {
        $meta = $this->meta($componentKey, $version, $locale);
        if ($meta === null) {
            throw new RuntimeException('Sprachpaket-Metadaten fehlen.');
        }
        $this->conn()->execute(
            'UPDATE language_packs SET reviewed = true WHERE component_key = :k AND version = :v AND locale = :l',
            ['k' => $componentKey, 'v' => $version, 'l' => $locale],
        );
    }

    /**
     * Löscht ein Pack unter Beachtung der Regeln (E41): aktive Komponente darf
     * Englisch nicht löschen; inaktive darf alles (inkl. Englisch).
     */
    public function deletePack(string $componentKey, string $version, string $locale, string $domain): void
    {
        $active = $this->isActive($componentKey);
        if (!$this->mayDelete($active, $locale)) {
            throw new RuntimeException('Englisch kann bei aktiver Komponente nicht gelöscht werden.');
        }
        $this->store->delete($componentKey, $version, $locale, $domain);
    }

    private function isActive(string $componentKey): bool
    {
        if ($componentKey === 'core') {
            return true;
        }
        $row = $this->conn()->execute(
            'SELECT status FROM modules WHERE module_key = :k',
            ['k' => $componentKey],
        )->fetch('assoc');

        return $row !== false && $row['status'] === 'active';
    }

    /**
     * Bereitet einen GUI-Import vor: parst die hochgeladene `.po` und meldet
     * Eintragszahl + Warnung, falls ein vorhandenes Ziel bereits editiert wurde.
     *
     * @return array{ok:bool,error:?string,count:int,exists:bool,existing_edited:bool,sample:list<string>}
     */
    public function importPreview(string $tmpPath, string $componentKey, string $version, string $locale): array
    {
        $content = is_file($tmpPath) ? (string)file_get_contents($tmpPath) : '';
        if (trim($content) === '' || !str_contains($content, 'msgid')) {
            return ['ok' => false, 'error' => 'Keine gültige PO-Datei (msgid fehlt).', 'count' => 0, 'exists' => false, 'existing_edited' => false, 'sample' => []];
        }
        $doc = PoDocument::parse($content);
        $editable = $doc->editableEntries();
        $existing = $this->meta($componentKey, $version, $locale);

        return [
            'ok' => true,
            'error' => null,
            'count' => count($editable),
            'exists' => $existing !== null,
            'existing_edited' => $existing !== null && $this->bool($existing['edited']),
            'sample' => array_slice(array_map(static fn ($e) => $e['id'], $editable), 0, 8),
        ];
    }

    /**
     * Führt den Import aus: speichert als unsigniertes Upload-Pack
     * (signed=false, reviewed=false, edited=false, source=upload).
     */
    public function importCommit(string $tmpPath, string $componentType, string $componentKey, string $version, string $locale, string $domain, ?string $actorId): void
    {
        $content = is_file($tmpPath) ? (string)file_get_contents($tmpPath) : '';
        if (trim($content) === '' || !str_contains($content, 'msgid')) {
            throw new RuntimeException('Keine gültige PO-Datei.');
        }
        // Re-Serialisieren normalisiert + validiert die Struktur.
        $normalized = PoDocument::parse($content)->serialize();
        $this->store->save($componentKey, $version, $locale, $normalized, [
            'type' => $componentType,
            'domain' => $domain,
            'signed' => false,
            'reviewed' => false,
            'edited' => false,
            'source' => 'upload',
            'uploadedBy' => $actorId,
        ]);
    }

    /**
     * Installierte Komponenten (für die Import-Auswahl): Core + Module mit
     * Version/Typ/Domain.
     *
     * @return list<array{key:string,type:string,version:string,domain:string,name:string}>
     */
    public function installedComponents(): array
    {
        $out = [[
            'key' => 'core', 'type' => 'core', 'version' => Application::CORE_VERSION,
            'domain' => 'default', 'name' => 'Core',
        ]];
        $rows = $this->conn()->execute(
            "SELECT module_key, name, version, type FROM modules WHERE status IN ('active','inactive','installed_inactive') ORDER BY module_key",
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            $type = (string)$r['type'] === 'extension' ? 'extension' : 'module';
            $out[] = [
                'key' => (string)$r['module_key'],
                'type' => $type,
                'version' => (string)$r['version'],
                'domain' => (string)$r['module_key'],
                'name' => (string)($r['name'] ?? $r['module_key']),
            ];
        }

        return $out;
    }
}
