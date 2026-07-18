<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Module UI kit (competitive lever): reusable, declarative CRUD building blocks
 * for **module UIs**, so a module renders list, detail and form views without
 * hand-written markup, in the core's unified (Bootstrap 5) style.
 *
 * All values are output HTML-safe (`h()`); column/field "labels" must already be
 * localized (`__()`) by the caller.
 *
 * Column definition (index):
 *   ['key'=>'name', 'label'=>'Name', 'type'=>'text|bool|datetime|badge|code',
 *    'link'=>callable($row):string|array|null, 'format'=>callable($value,$row):string,
 *    'badge'=>callable($value):string  // Bootstrap variant (success/secondary/…)]
 * Action (index/options['actions'][]):
 *   ['label'=>'Edit', 'url'=>callable($row):string|array, 'class'=>'btn …',
 *    'confirm'=>'Really delete?']
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\FormHelper $Form
 * @property \Cake\View\Helper\UrlHelper $Url
 */
class UiKitHelper extends Helper
{
    /**
     * @var list<string>
     */
    protected array $helpers = ['Html', 'Form', 'Url'];

    /**
     * Table list from rows + column specification.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $columns
     * @param array<string,mixed> $options actions, empty (text), class, id
     */
    public function index(array $rows, array $columns, array $options = []): string
    {
        $actions = (array)($options['actions'] ?? []);
        $select = (bool)($options['select'] ?? false);
        $idKey = (string)($options['idKey'] ?? 'id');
        $tableClass = (string)($options['class'] ?? 'table table-sm table-hover align-middle');
        $emptyText = (string)($options['empty'] ?? __d('default', 'uikit.empty'));
        $colCount = count($columns) + ($actions !== [] ? 1 : 0) + ($select ? 1 : 0);

        $head = '';
        if ($select) {
            // Header "toggle all" checkbox — minimal inline toggle, no JS framework.
            $head .= '<th scope="col" style="width:1%"><input type="checkbox" class="form-check-input" '
                . 'onclick="this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\')'
                . '.forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="'
                . h(__d('default', 'uikit.select_all')) . '"></th>';
        }
        foreach ($columns as $c) {
            $head .= '<th scope="col">' . h((string)($c['label'] ?? $c['key'] ?? '')) . '</th>';
        }
        if ($actions !== []) {
            $head .= '<th scope="col" class="text-end">' . h(__d('default', 'uikit.actions')) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $cells = '';
            if ($select) {
                $cells .= '<td><input type="checkbox" class="form-check-input" name="ids[]" value="'
                    . h((string)($row[$idKey] ?? '')) . '"></td>';
            }
            foreach ($columns as $c) {
                $cells .= '<td>' . $this->cell($row, $c) . '</td>';
            }
            if ($actions !== []) {
                $cells .= '<td class="text-end text-nowrap">' . $this->actionButtons($row, $actions) . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        if ($rows === []) {
            $body = '<tr><td colspan="' . $colCount . '" class="text-muted">' . h($emptyText) . '</td></tr>';
        }

        $idAttr = isset($options['id']) ? ' id="' . h((string)$options['id']) . '"' : '';

        return '<table class="' . h($tableClass) . '"' . $idAttr . '><thead><tr>' . $head . '</tr></thead><tbody>'
            . $body . '</tbody></table>';
    }

    /**
     * Bulk-action bar (submit buttons) for a list with `select`. MUST sit with
     * the list inside ONE `Form->create()/end()`; the controller reads the
     * selection via `ids[]` and the chosen action via its `name`/`value`.
     * Button: ['label','value','name'?(=op),'class'?,'confirm'?].
     *
     * @param list<array<string,mixed>> $buttons
     */
    public function bulkActions(array $buttons): string
    {
        $out = '';
        foreach ($buttons as $b) {
            $name = (string)($b['name'] ?? 'op');
            $attrs = 'type="submit" class="' . h((string)($b['class'] ?? 'btn btn-sm btn-outline-secondary')) . '"'
                . ' name="' . h($name) . '" value="' . h((string)($b['value'] ?? '')) . '"';
            if (isset($b['confirm'])) {
                // Shared Bootstrap confirm modal (ui.js) instead of window.confirm().
                $attrs .= ' data-confirm="' . h((string)$b['confirm']) . '"';
            }
            $out .= '<button ' . $attrs . '>' . h((string)($b['label'] ?? '')) . '</button> ';
        }

        return $out !== '' ? '<div class="btn-toolbar gap-2 mb-2">' . trim($out) . '</div>' : '';
    }

    /**
     * A single destructive POST action as a standalone form + submit button that
     * routes through the shared Bootstrap confirm modal (ui.js, [data-confirm])
     * instead of `Form->postLink(['confirm'=>…])`'s native `window.confirm()`.
     * CSRF is added by the FormHelper. Optional `variant` sets the modal OK button
     * class (default btn-danger).
     *
     * @param array<string,mixed>|string $url
     * @param array<string,mixed> $options class, variant, okLabel
     */
    public function confirmPost(string $label, array|string $url, string $confirm, array $options = []): string
    {
        $attrs = [
            'type' => 'submit',
            'class' => (string)($options['class'] ?? 'btn btn-sm btn-outline-danger'),
            'data-confirm' => $confirm,
            'escapeTitle' => false,
        ];
        if (isset($options['variant'])) {
            $attrs['data-confirm-variant'] = (string)$options['variant'];
        }
        if (isset($options['okLabel'])) {
            $attrs['data-confirm-ok'] = (string)$options['okLabel'];
        }

        return $this->Form->create(null, ['url' => $url, 'class' => 'd-inline'])
            . $this->Form->button(h($label), $attrs)
            . $this->Form->end();
    }

    /**
     * Detail/read view of a record as a definition list.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $options
     */
    public function detail(array $row, array $fields, array $options = []): string
    {
        $rowsHtml = '';
        foreach ($fields as $f) {
            $label = (string)($f['label'] ?? $f['key'] ?? '');
            $rowsHtml .= '<dt class="col-sm-3">' . h($label) . '</dt>'
                . '<dd class="col-sm-9">' . $this->cell($row, $f) . '</dd>';
        }
        $class = (string)($options['class'] ?? 'row');

        return '<dl class="' . h($class) . '">' . $rowsHtml . '</dl>';
    }

    /**
     * Renders form fields from a specification (INSIDE a `Form->create()/end()`
     * opened by the module). Field:
     *   ['key'=>'name','label'=>'Name','input'=>'text|textarea|select|checkbox|…',
     *    'options'=>[…] (for select),'required'=>bool,'help'=>'…','value'=>mixed]
     *
     * @param list<array<string,mixed>> $fields
     */
    public function fields(array $fields): string
    {
        $out = '';
        foreach ($fields as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            // Reference field (select over elements managed elsewhere, possibly in
            // another module): renders an input-group with an "open in new tab"
            // link to the element's admin page and an options-refresh button, so a
            // missing element can be created without abandoning this form.
            if (isset($f['reference']) && is_array($f['reference']) && (string)($f['input'] ?? 'text') === 'select') {
                $out .= $this->referenceField($key, $f);
                continue;
            }
            $opts = [
                'label' => $f['label'] ?? $key,
                'type' => (string)($f['input'] ?? 'text'),
                'class' => (string)($f['input'] ?? 'text') === 'select' ? 'form-select' : 'form-control',
                'required' => (bool)($f['required'] ?? false),
            ];
            if (isset($f['value'])) {
                $opts['value'] = $f['value'];
            }
            if (isset($f['options'])) {
                $opts['options'] = $f['options'];
            }
            if (isset($f['help'])) {
                $opts['help'] = (string)$f['help'];
            }
            $out .= '<div class="mb-3">' . $this->Form->control($key, $opts) . '</div>';
        }

        return $out;
    }

    /**
     * A `select` whose elements are managed on another admin page (same or other
     * module), rendered as an input-group: the select, an "open in new tab" link
     * to that page (`reference.url`, `target=_blank` with `rel=noopener`) and an
     * options-refresh button (`reference.options_url`) wired by ui.js
     * (`[data-options-refresh]`) to re-fetch `{options:[{value,label}]}` and
     * rebuild the select in place, keeping the current selection. Spares the
     * user the leave-form/create/come-back/re-enter round trip.
     *
     * Guard rails:
     *  - Both URLs must be app-relative (single leading '/'): anything else is
     *    dropped, so a spec cannot point the browser at a foreign origin.
     *  - `reference.area` (optional): the link only renders when the current
     *    viewer holds that admin area (`userAreas` view var) — visibility =
     *    server-side authorization, never a link into a guaranteed 403.
     *
     * @param array<string,mixed> $f field spec (key/label/options/value/…, `reference`)
     */
    private function referenceField(string $key, array $f): string
    {
        $ref = (array)$f['reference'];
        $id = 'uikit-ref-' . (string)preg_replace('/[^a-zA-Z0-9_-]/', '-', $key);
        $appRelative = static fn(string $u): bool => str_starts_with($u, '/') && !str_starts_with($u, '//');

        $selectOpts = [
            'id' => $id,
            'class' => 'form-select',
            'required' => (bool)($f['required'] ?? false),
        ];
        if (isset($f['value'])) {
            $selectOpts['value'] = $f['value'];
        }
        if (isset($f['empty'])) {
            $selectOpts['empty'] = $f['empty'];
        }
        $select = $this->Form->select($key, (array)($f['options'] ?? []), $selectOpts);

        $buttons = '';
        $url = (string)($ref['url'] ?? '');
        $areas = (array)($this->getView()->get('userAreas') ?? []);
        $linkAllowed = !isset($ref['area']) || in_array((string)$ref['area'], $areas, true);
        if ($url !== '' && $appRelative($url) && $linkAllowed) {
            $openLabel = __d('default', 'uikit.ref_open');
            $buttons .= '<a class="btn btn-outline-secondary" href="' . h($url) . '" target="_blank" rel="noopener"'
                . ' aria-label="' . h($openLabel) . '" title="' . h($openLabel) . '">&#8599;</a>';
        }
        $optionsUrl = (string)($ref['options_url'] ?? '');
        if ($optionsUrl !== '' && $appRelative($optionsUrl)) {
            $refreshLabel = __d('default', 'uikit.ref_refresh');
            $buttons .= '<button type="button" class="btn btn-outline-secondary"'
                . ' data-options-refresh="' . h($optionsUrl) . '" data-options-target="#' . h($id) . '"'
                . ' aria-label="' . h($refreshLabel) . '" title="' . h($refreshLabel) . '">&#10227;</button>';
        }

        $label = '<label class="form-label" for="' . h($id) . '">' . h((string)($f['label'] ?? $key)) . '</label>';
        $help = isset($f['help']) ? '<div class="form-text">' . h((string)$f['help']) . '</div>' : '';

        return '<div class="mb-3">' . $label
            . '<div class="input-group">' . $select . $buttons . '</div>'
            . $help . '</div>';
    }

    /**
     * Bootstrap accordion around an inline create/edit form — the Core admin
     * idiom (list first, form folded; cf. templates/Admin/Localization/index.php),
     * so modules stop hand-rolling the card/accordion chrome per template.
     *
     * `$bodyHtml` is finished, caller-produced HTML (typically
     * `Form->create()` + `UiKit->fields()` + `Form->end()`) and is emitted RAW;
     * the title is escaped. The Bootstrap Collapse JS this relies on
     * (`bootstrap.bundle.min`) is loaded by both the admin shell and the module
     * layout, so the accordion works on every module-rendered page.
     *
     * Options:
     *   - `open` (bool, default false): render expanded — e.g. when redisplaying
     *     a prefilled edit or validation-failed form, so the form does not hide
     *     itself. Collapsed by default keeps the list first.
     *   - `id` (string): explicit id prefix when a stable anchor is needed;
     *     defaults to an auto-incremented unique id (multiple accordions per page).
     *
     * @param array<string,mixed> $options
     */
    public function formAccordion(string $title, string $bodyHtml, array $options = []): string
    {
        $open = (bool)($options['open'] ?? false);
        $id = (string)($options['id'] ?? ('uikit-accordion-' . ++self::$accordionSeq));
        $bodyId = $id . '-body';

        return '<div class="accordion mb-4" id="' . h($id) . '"><div class="accordion-item">'
            . '<h2 class="accordion-header">'
            . '<button class="accordion-button' . ($open ? '' : ' collapsed') . '" type="button"'
            . ' data-bs-toggle="collapse" data-bs-target="#' . h($bodyId) . '"'
            . ' aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . h($bodyId) . '">'
            . h($title) . '</button></h2>'
            . '<div id="' . h($bodyId) . '" class="accordion-collapse collapse' . ($open ? ' show' : '') . '">'
            . '<div class="accordion-body">' . $bodyHtml . '</div>'
            . '</div></div></div>';
    }

    /** Per-request sequence for unique {@see formAccordion()} ids. */
    private static int $accordionSeq = 0;

    /**
     * Sortable column header: a link that toggles between ascending/descending,
     * with a direction arrow. `$query` = current query parameters (with `sort`/`dir`).
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|string|null $url Target (Cake route array, string, or current)
     */
    public function sortHeader(string $label, string $sortKey, array $query, array|string|null $url = null): string
    {
        $curSort = (string)($query['sort'] ?? '');
        $curDir = strtolower((string)($query['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $nextDir = $curSort === $sortKey && $curDir === 'asc' ? 'desc' : 'asc';
        $arrow = $curSort === $sortKey ? ($curDir === 'asc' ? ' ↑' : ' ↓') : '';
        $href = $this->withQuery($url, array_merge($query, ['sort' => $sortKey, 'dir' => $nextDir]));
        $ariaSort = $curSort === $sortKey ? ' aria-sort="' . ($curDir === 'asc' ? 'ascending' : 'descending') . '"' : '';

        return '<th scope="col"' . $ariaSort . '><a class="text-decoration-none text-reset" href="' . h($href) . '">'
            . h($label . $arrow) . '</a></th>';
    }

    /**
     * Bootstrap pagination. Empty when there is only one page. Appends `page` to
     * the (otherwise preserved) query parameters.
     *
     * @param array<string,mixed>|string|null $url
     * @param array<string,mixed> $query
     */
    public function paginate(int $page, int $perPage, int $total, array|string|null $url = null, array $query = []): string
    {
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        if ($pages <= 1) {
            return '';
        }
        $page = max(1, min($page, $pages));
        $item = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($url, $query): string {
            $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
            if ($disabled || $active) {
                return '<li class="' . $cls . '"><span class="page-link">' . h($label) . '</span></li>';
            }
            $href = $this->withQuery($url, array_merge($query, ['page' => $p]));

            return '<li class="' . $cls . '"><a class="page-link" href="' . h($href) . '">' . h($label) . '</a></li>';
        };
        $out = $item($page - 1, '‹', false, $page <= 1);
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($start > 1) {
            $out .= $item(1, '1') . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
        }
        for ($p = $start; $p <= $end; $p++) {
            $out .= $item($p, (string)$p, $p === $page);
        }
        if ($end < $pages) {
            $out .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $item($pages, (string)$pages);
        }
        $out .= $item($page + 1, '›', false, $page >= $pages);

        return '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">' . $out . '</ul></nav>';
    }

    /**
     * GET filter bar from a field specification (text/select).
     *
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $values current values
     * @param array<string,mixed> $options submit (label), url
     */
    public function filters(array $fields, array $values = [], array $options = []): string
    {
        $createOpts = ['type' => 'get', 'class' => 'row g-2 align-items-end mb-3'];
        if (isset($options['url'])) {
            $createOpts['url'] = $options['url'];
        }
        $out = $this->Form->create(null, $createOpts);
        foreach ($fields as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $label = (string)($f['label'] ?? $key);
            $val = $values[$key] ?? '';
            if ((string)($f['input'] ?? 'text') === 'select') {
                $input = $this->Form->select($key, (array)($f['options'] ?? []), [
                    'value' => $val,
                    'empty' => $f['empty'] ?? true,
                    'class' => 'form-select form-select-sm',
                ]);
            } else {
                $input = $this->Form->control($key, [
                    'label' => false,
                    'value' => $val,
                    'class' => 'form-control form-control-sm',
                    'placeholder' => (string)($f['placeholder'] ?? ''),
                ]);
            }
            $out .= '<div class="col-auto"><label class="form-label small mb-0">' . h($label) . '</label>' . $input . '</div>';
        }
        $out .= '<div class="col-auto">'
            . $this->Form->button((string)($options['submit'] ?? __d('default', 'uikit.filter')), ['class' => 'btn btn-primary btn-sm'])
            . '</div>';
        $out .= $this->Form->end();

        return $out;
    }

    /**
     * @param array<string,mixed>|string|null $url
     * @param array<string,mixed> $query
     */
    private function withQuery(array|string|null $url, array $query): string
    {
        if (is_string($url) && $url !== '') {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $route = is_array($url) ? $url : [];

        return $this->Url->build($route + ['?' => $query]);
    }

    /**
     * Standard active/inactive status badge (green/grey), so every admin list
     * renders the same status pill instead of plain text or ad-hoc variants.
     * Accepts a bool or a truthy DB value (`'t'`, `'true'`, `1`, …).
     */
    public function activeBadge(mixed $active, ?string $activeLabel = null, ?string $inactiveLabel = null): string
    {
        $on = filter_var($active, FILTER_VALIDATE_BOOLEAN);
        $label = $on ? ($activeLabel ?? __d('default', 'uikit.yes')) : ($inactiveLabel ?? __d('default', 'uikit.no'));

        return '<span class="badge text-bg-' . ($on ? 'success' : 'secondary') . '">' . h((string)$label) . '</span>';
    }

    /**
     * Formats a single value HTML-safe according to its type.
     */
    public function value(mixed $val, string $type = 'text'): string
    {
        return match ($type) {
            'bool' => (bool)$val
                ? '<span class="badge text-bg-success">' . h(__d('default', 'uikit.yes')) . '</span>'
                : '<span class="badge text-bg-secondary">' . h(__d('default', 'uikit.no')) . '</span>',
            'code' => '<code class="small">' . h((string)$val) . '</code>',
            'datetime' => '<span class="text-nowrap small">' . h((string)$val) . '</span>',
            'badge' => '<span class="badge text-bg-light">' . h((string)$val) . '</span>',
            default => h((string)$val),
        };
    }

    /**
     * Renders a table/detail cell according to the column specification (format/link/type).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $col
     */
    private function cell(array $row, array $col): string
    {
        $key = (string)($col['key'] ?? '');
        $raw = $key !== '' ? ($row[$key] ?? null) : null;

        if (isset($col['format']) && is_callable($col['format'])) {
            // A custom format already yields finished (safe) HTML/text -> escape it.
            $html = h((string)($col['format'])($raw, $row));
        } elseif (isset($col['badge']) && is_callable($col['badge'])) {
            $variant = (string)($col['badge'])($raw);
            $html = '<span class="badge text-bg-' . h($variant) . '">' . h((string)$raw) . '</span>';
        } else {
            $html = $this->value($raw, (string)($col['type'] ?? 'text'));
        }

        if (isset($col['link']) && is_callable($col['link'])) {
            $url = ($col['link'])($row);
            if ($url !== null && $url !== '') {
                // Html->link escapes the (plain-text) link text; we want to keep the
                // already-formatted HTML -> build it ourselves with an escaped URL.
                return '<a href="' . h($this->Url->build($url)) . '">' . $html . '</a>';
            }
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $actions
     */
    private function actionButtons(array $row, array $actions): string
    {
        $out = '';
        foreach ($actions as $a) {
            $url = isset($a['url']) && is_callable($a['url']) ? ($a['url'])($row) : ($a['url'] ?? '#');
            $attr = ['class' => (string)($a['class'] ?? 'btn btn-sm btn-outline-secondary')];
            if (isset($a['confirm'])) {
                // Route through the shared confirm modal (ui.js) instead of native confirm.
                $attr['data-confirm'] = (string)$a['confirm'];
            }
            $out .= $this->Html->link((string)($a['label'] ?? ''), $url, $attr) . ' ';
        }

        return trim($out);
    }
}
