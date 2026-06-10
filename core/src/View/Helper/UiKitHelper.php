<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * Modul-UI-Kit (Wettbewerbs-Hebel): wiederverwendbare, deklarative CRUD-Bausteine
 * für **Modul-Oberflächen**, damit ein Modul Listen-, Detail- und Formular-Ansichten
 * ohne handgeschriebenes Markup im einheitlichen (Bootstrap-5-)Stil des Core rendert.
 *
 * Alle Werte werden HTML-sicher ausgegeben (`h()`); Spalten-/Feld-„Labels" sind
 * vom Aufrufer bereits lokalisiert (`__()`) zu übergeben.
 *
 * Spaltendefinition (index):
 *   ['key'=>'name', 'label'=>'Name', 'type'=>'text|bool|datetime|badge|code',
 *    'link'=>callable($row):string|array|null, 'format'=>callable($value,$row):string,
 *    'badge'=>callable($value):string  // Bootstrap-Variante (success/secondary/…)]
 * Aktion (index/options['actions'][]):
 *   ['label'=>'Bearbeiten', 'url'=>callable($row):string|array, 'class'=>'btn …',
 *    'confirm'=>'Wirklich löschen?']
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\FormHelper $Form
 * @property \Cake\View\Helper\UrlHelper $Url
 */
class UiKitHelper extends Helper
{
    /** @var list<string> */
    protected array $helpers = ['Html', 'Form', 'Url'];

    /**
     * Tabellenliste aus Zeilen + Spaltenspezifikation.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $columns
     * @param array<string,mixed> $options actions, empty (Text), class, id
     */
    public function index(array $rows, array $columns, array $options = []): string
    {
        $actions = (array)($options['actions'] ?? []);
        $tableClass = (string)($options['class'] ?? 'table table-sm table-hover align-middle');
        $emptyText = (string)($options['empty'] ?? __d('default', 'uikit.empty'));
        $colCount = count($columns) + ($actions !== [] ? 1 : 0);

        $head = '';
        foreach ($columns as $c) {
            $head .= '<th>' . h((string)($c['label'] ?? $c['key'] ?? '')) . '</th>';
        }
        if ($actions !== []) {
            $head .= '<th class="text-end">' . h(__d('default', 'uikit.actions')) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $cells = '';
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
     * Detail-/Lese-Ansicht eines Datensatzes als Definitionsliste.
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
     * Rendert Formularfelder aus einer Spezifikation (INNERHALB eines vom Modul
     * geöffneten `Form->create()/end()`). Feld:
     *   ['key'=>'name','label'=>'Name','input'=>'text|textarea|select|checkbox|…',
     *    'options'=>[…] (für select),'required'=>bool,'help'=>'…','value'=>mixed]
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
     * Formatiert einen einzelnen Wert HTML-sicher nach Typ.
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
     * Rendert eine Tabellen-/Detail-Zelle nach Spaltenspezifikation (Format/Link/Typ).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $col
     */
    private function cell(array $row, array $col): string
    {
        $key = (string)($col['key'] ?? '');
        $raw = $key !== '' ? ($row[$key] ?? null) : null;

        if (isset($col['format']) && is_callable($col['format'])) {
            // Eigenes Format liefert bereits fertiges (sicheres) HTML/Text -> escapen.
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
                // Html->link escapt den (Klartext-)Linktext; wir wollen das bereits
                // formatierte HTML behalten -> selbst bauen mit escapter URL.
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
                $attr['confirm'] = (string)$a['confirm'];
            }
            $out .= $this->Html->link((string)($a['label'] ?? ''), $url, $attr) . ' ';
        }

        return trim($out);
    }
}
