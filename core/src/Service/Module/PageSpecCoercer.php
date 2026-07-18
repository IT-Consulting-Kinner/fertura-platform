<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Utility\AppUrl;
use Cake\Core\Configure;
use Cake\Log\Log;

/**
 * Coerces a module-provided **page spec** (`result['page']`, untrusted shape)
 * into the safe, purely-data structure the Core page renderer
 * (`templates/ModulePage/render.php`) consumes — the module-page analog of
 * `ModuleWebController::coerceBreadcrumb()`. Design: docs/module-page-spec-design.md.
 *
 * Contract (v1) — a page is a list of sections, each mapping 1:1 onto a UiKit
 * building block:
 *   - `filters`        → UiKit->filters()    (GET filter bar)
 *   - `table`          → UiKit->index()      (+ optional paginate)
 *   - `form_accordion` → UiKit->formAccordion(Form + UiKit->fields())
 *   - `detail`         → UiKit->detail()
 *   - `alert`          → Bootstrap alert (variant allowlisted)
 *   - `html`           → raw pass-through (LAST RESORT — a module template is
 *                        arbitrary PHP already, so this adds no new trust, but
 *                        every `html` section weakens the "one Core change
 *                        restyles every module" guarantee)
 *
 * Rules:
 *   - Only data crosses the boundary: callables (UiKit's `link`/`format`/
 *     `badge`/`url`) are NOT accepted — links come from `link_template` /
 *     `url_template` strings with flat `{key}` placeholders expanded per row
 *     ({@see self::expandTemplate()}, values rawurlencode'd).
 *   - Every URL/template must be a safe app-relative path
 *     ({@see AppUrl::isSafeRelative}); offending values are dropped.
 *   - Unknown section types and unknown keys are dropped SILENTLY (a spec
 *     built against a newer Core degrades on an older one instead of 500ing);
 *     in debug mode each dropped section is logged for the module author.
 *   - Versioning is additive only (E157 discipline): new section types and new
 *     optional keys may be added, nothing may be renamed or removed.
 *   - Not in v1 (additive later): row selection + bulk actions.
 */
final class PageSpecCoercer
{
    private const ALERT_VARIANTS = [
        'primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark',
    ];

    /** Column/detail-field value renderers UiKit supports. */
    private const VALUE_TYPES = ['text', 'bool', 'datetime', 'badge', 'code'];

    /**
     * @param array<mixed> $raw module-provided page spec (untrusted)
     * @return array{sections: list<array<string,mixed>>}
     */
    public function coerce(array $raw): array
    {
        $sections = [];
        foreach ((array)($raw['sections'] ?? []) as $i => $s) {
            $coerced = null;
            if (is_array($s)) {
                $coerced = match ($s['type'] ?? null) {
                    'filters' => $this->filters($s),
                    'table' => $this->table($s),
                    'form_accordion' => $this->formAccordion($s),
                    'detail' => $this->detail($s),
                    'alert' => $this->alert($s),
                    'html' => ['type' => 'html', 'html' => (string)($s['html'] ?? '')],
                    default => null,
                };
            }
            if ($coerced !== null) {
                $sections[] = $coerced;
            } elseif (Configure::read('debug')) {
                // Author-time aid only: dropped sections stay silent in prod.
                Log::debug(sprintf(
                    'PageSpec: Section %s (type=%s) verworfen — unbekannter Typ oder ungültige Form.',
                    (string)$i,
                    is_array($s) ? (string)($s['type'] ?? '?') : gettype($s),
                ));
            }
        }

        return ['sections' => $sections];
    }

    /**
     * Expands a `{key}` link/url template against a row, rawurlencode-ing each
     * substituted value, and re-validates the result. Returns null when the
     * expanded URL is not a safe app-relative path. Flat keys only (v1).
     *
     * @param array<string,mixed> $row
     */
    public static function expandTemplate(string $template, array $row): ?string
    {
        $url = (string)preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static function (array $m) use ($row): string {
                $v = $row[$m[1]] ?? '';

                return rawurlencode(is_scalar($v) ? (string)$v : '');
            },
            $template,
        );

        return AppUrl::isSafeRelative($url) ? $url : null;
    }

    /**
     * @param array<mixed> $s
     * @return array<string,mixed>
     */
    private function filters(array $s): array
    {
        $out = ['type' => 'filters', 'fields' => $this->fieldSpecs($s['fields'] ?? null)];
        $out['values'] = $this->scalarMap($s['values'] ?? null);
        if (isset($s['submit']) && is_scalar($s['submit'])) {
            $out['submit'] = (string)$s['submit'];
        }
        if (isset($s['url']) && is_string($s['url']) && AppUrl::isSafeRelative($s['url'])) {
            $out['url'] = $s['url'];
        }

        return $out;
    }

    /**
     * @param array<mixed> $s
     * @return array<string,mixed>|null null when the table declares no columns
     */
    private function table(array $s): ?array
    {
        $columns = [];
        foreach ((array)($s['columns'] ?? []) as $c) {
            if (!is_array($c) || !isset($c['key']) || !is_scalar($c['key'])) {
                continue;
            }
            $col = ['key' => (string)$c['key']];
            if (isset($c['label']) && is_scalar($c['label'])) {
                $col['label'] = (string)$c['label'];
            }
            if (isset($c['type']) && in_array($c['type'], self::VALUE_TYPES, true)) {
                $col['type'] = (string)$c['type'];
            }
            if (
                isset($c['link_template']) && is_string($c['link_template'])
                && $this->templateIsSafe($c['link_template'])
            ) {
                $col['link_template'] = $c['link_template'];
            }
            $columns[] = $col;
        }
        if ($columns === []) {
            return null;
        }

        $rows = [];
        foreach ((array)($s['rows'] ?? []) as $r) {
            if (is_array($r)) {
                $rows[] = $this->scalarMap($r);
            }
        }

        $actions = [];
        foreach ((array)($s['actions'] ?? []) as $a) {
            if (
                !is_array($a) || !isset($a['label'], $a['url_template'])
                || !is_scalar($a['label']) || !is_string($a['url_template'])
                || !$this->templateIsSafe($a['url_template'])
            ) {
                continue;
            }
            $action = ['label' => (string)$a['label'], 'url_template' => $a['url_template']];
            if (isset($a['class']) && is_scalar($a['class'])) {
                $action['class'] = (string)$a['class'];
            }
            if (isset($a['confirm']) && is_scalar($a['confirm'])) {
                $action['confirm'] = (string)$a['confirm'];
            }
            $actions[] = $action;
        }

        $out = ['type' => 'table', 'columns' => $columns, 'rows' => $rows, 'actions' => $actions];
        if (isset($s['empty']) && is_scalar($s['empty'])) {
            $out['empty'] = (string)$s['empty'];
        }
        if (isset($s['paginate']) && is_array($s['paginate'])) {
            $p = $s['paginate'];
            $out['paginate'] = [
                'page' => max(1, (int)($p['page'] ?? 1)),
                'per_page' => max(1, (int)($p['per_page'] ?? 20)),
                'total' => max(0, (int)($p['total'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed> $s
     * @return array<string,mixed>
     */
    private function formAccordion(array $s): array
    {
        $out = [
            'type' => 'form_accordion',
            'title' => isset($s['title']) && is_scalar($s['title']) ? (string)$s['title'] : '',
            'open' => (bool)($s['open'] ?? false),
            'fields' => $this->fieldSpecs($s['fields'] ?? null),
        ];
        if (isset($s['id']) && is_scalar($s['id'])) {
            $out['id'] = (string)$s['id'];
        }
        if (isset($s['url']) && is_string($s['url']) && AppUrl::isSafeRelative($s['url'])) {
            // Absent/unsafe url -> the form posts back to the current page.
            $out['url'] = $s['url'];
        }
        if (isset($s['submit']) && is_scalar($s['submit'])) {
            $out['submit'] = (string)$s['submit'];
        }

        return $out;
    }

    /**
     * @param array<mixed> $s
     * @return array<string,mixed>|null null when no fields are declared
     */
    private function detail(array $s): ?array
    {
        $fields = [];
        foreach ((array)($s['fields'] ?? []) as $f) {
            if (!is_array($f) || !isset($f['key']) || !is_scalar($f['key'])) {
                continue;
            }
            $spec = ['key' => (string)$f['key']];
            if (isset($f['label']) && is_scalar($f['label'])) {
                $spec['label'] = (string)$f['label'];
            }
            if (isset($f['type']) && in_array($f['type'], self::VALUE_TYPES, true)) {
                $spec['type'] = (string)$f['type'];
            }
            $fields[] = $spec;
        }
        if ($fields === []) {
            return null;
        }

        return [
            'type' => 'detail',
            'row' => $this->scalarMap($s['row'] ?? null),
            'fields' => $fields,
        ];
    }

    /**
     * @param array<mixed> $s
     * @return array<string,mixed>
     */
    private function alert(array $s): array
    {
        $variant = isset($s['variant']) && in_array($s['variant'], self::ALERT_VARIANTS, true)
            ? (string)$s['variant'] : 'info';

        return [
            'type' => 'alert',
            'variant' => $variant,
            'text' => isset($s['text']) && is_scalar($s['text']) ? (string)$s['text'] : '',
        ];
    }

    /**
     * Form/filter field specs — the UiKit `fields()`/`filters()` vocabulary,
     * data keys only. `reference` URLs are validated here as well (the UiKit
     * re-checks at render time; both layers fail closed).
     *
     * @return list<array<string,mixed>>
     */
    private function fieldSpecs(mixed $raw): array
    {
        $out = [];
        foreach ((array)$raw as $f) {
            if (!is_array($f) || !isset($f['key']) || !is_scalar($f['key']) || (string)$f['key'] === '') {
                continue;
            }
            $spec = ['key' => (string)$f['key']];
            foreach (['label', 'input', 'help', 'placeholder'] as $k) {
                if (isset($f[$k]) && is_scalar($f[$k])) {
                    $spec[$k] = (string)$f[$k];
                }
            }
            if (isset($f['required'])) {
                $spec['required'] = (bool)$f['required'];
            }
            if (isset($f['value']) && is_scalar($f['value'])) {
                $spec['value'] = $f['value'];
            }
            if (isset($f['empty']) && (is_bool($f['empty']) || is_scalar($f['empty']))) {
                $spec['empty'] = $f['empty'];
            }
            if (isset($f['options']) && is_array($f['options'])) {
                $spec['options'] = $this->scalarMap($f['options']);
            }
            if (isset($f['reference']) && is_array($f['reference'])) {
                $ref = [];
                foreach (['url', 'options_url'] as $k) {
                    if (
                        isset($f['reference'][$k]) && is_string($f['reference'][$k])
                        && AppUrl::isSafeRelative($f['reference'][$k])
                    ) {
                        $ref[$k] = $f['reference'][$k];
                    }
                }
                if (isset($f['reference']['area']) && is_scalar($f['reference']['area'])) {
                    $ref['area'] = (string)$f['reference']['area'];
                }
                if ($ref !== []) {
                    $spec['reference'] = $ref;
                }
            }
            $out[] = $spec;
        }

        return $out;
    }

    /**
     * Keeps only scalar/null values of a map (drops callables, objects, nested
     * arrays) — the "only data crosses the boundary" rule for row/value bags.
     *
     * @return array<string,mixed>
     */
    private function scalarMap(mixed $raw): array
    {
        $out = [];
        foreach ((array)$raw as $k => $v) {
            if ($v === null || is_scalar($v)) {
                $out[(string)$k] = $v;
            }
        }

        return $out;
    }

    /**
     * A template is acceptable when, with every `{key}` placeholder replaced by
     * a harmless value, it yields a safe app-relative path. Placeholder VALUES
     * are rawurlencode'd at expansion, so they cannot re-introduce an authority.
     */
    private function templateIsSafe(string $template): bool
    {
        $probe = (string)preg_replace('/\{[A-Za-z0-9_]+\}/', 'x', $template);

        return AppUrl::isSafeRelative($probe);
    }
}
