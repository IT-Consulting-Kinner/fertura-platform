<?php
/**
 * Generic renderer for module page specs (docs/module-page-spec-design.md):
 * iterates the coerced sections and maps each onto its UiKit building block.
 * The spec arrives fully coerced ({@see \App\Service\Module\PageSpecCoercer})
 * — pure data, safe app-relative URLs only — so this template contains the ONLY
 * place where link/url templates become real callables/hrefs.
 *
 * @var \App\View\AppView $this
 * @var array{sections: list<array<string, mixed>>} $pageSpec
 */

use App\Service\Module\PageSpecCoercer;

foreach ($pageSpec['sections'] as $section) {
    switch ($section['type']) {
        case 'filters':
            $opts = [];
            if (isset($section['submit'])) {
                $opts['submit'] = $section['submit'];
            }
            if (isset($section['url'])) {
                $opts['url'] = $section['url'];
            }
            echo $this->UiKit->filters($section['fields'], $section['values'], $opts);
            break;

        case 'table':
            $columns = [];
            foreach ($section['columns'] as $col) {
                if (isset($col['link_template'])) {
                    $tpl = $col['link_template'];
                    // expandTemplate rawurlencodes row values and re-validates the
                    // result, so a hostile cell value cannot smuggle an authority.
                    $col['link'] = static fn(array $row): ?string => PageSpecCoercer::expandTemplate($tpl, $row);
                    unset($col['link_template']);
                }
                $columns[] = $col;
            }
            $actions = [];
            foreach ($section['actions'] as $a) {
                $tpl = $a['url_template'];
                $a['url'] = static fn(array $row): string => (string)PageSpecCoercer::expandTemplate($tpl, $row);
                unset($a['url_template']);
                $actions[] = $a;
            }
            $tableOpts = ['actions' => $actions];
            if (isset($section['empty'])) {
                $tableOpts['empty'] = $section['empty'];
            }
            echo $this->UiKit->index($section['rows'], $columns, $tableOpts);
            if (isset($section['paginate'])) {
                $p = $section['paginate'];
                // Pass the CURRENT path explicitly: with $url=null the UiKit falls
                // back to array-URL generation, which resolves to the bare
                // /module-web/dispatch fallback route (no moduleKey pass args) —
                // every pagination link would 500. The string branch simply
                // appends the query to this page's own URL.
                echo $this->UiKit->paginate(
                    $p['page'],
                    $p['per_page'],
                    $p['total'],
                    $this->getRequest()->getUri()->getPath(),
                    $this->getRequest()->getQueryParams(),
                );
            }
            break;

        case 'form_accordion':
            // The Core opens the form itself -> the CSRF token is always present.
            $createOpts = [];
            if (isset($section['url'])) {
                $createOpts['url'] = $section['url'];
            }
            $hidden = '';
            foreach ($section['hidden'] ?? [] as $hKey => $hVal) {
                $hidden .= $this->Form->hidden((string)$hKey, ['value' => $hVal]);
            }
            $body = $this->Form->create(null, $createOpts)
                . $hidden
                . $this->UiKit->fields($section['fields'])
                // FormHelper escapes the button label by default.
                . $this->Form->button(
                    (string)($section['submit'] ?? __d('default', 'uikit.save')),
                    ['class' => 'btn btn-primary'],
                )
                . $this->Form->end();
            $accOpts = ['open' => $section['open']];
            if (isset($section['id'])) {
                $accOpts['id'] = $section['id'];
            }
            echo $this->UiKit->formAccordion($section['title'], $body, $accOpts);
            break;

        case 'detail':
            echo $this->UiKit->detail($section['row'], $section['fields']);
            break;

        case 'alert':
            echo '<div class="alert alert-' . h($section['variant']) . '" role="alert">'
                . h($section['text']) . '</div>';
            break;

        case 'html':
            // Raw pass-through (last resort by contract; a module template is
            // arbitrary PHP already, so this adds no new trust boundary).
            echo $section['html'];
            break;
    }
}
