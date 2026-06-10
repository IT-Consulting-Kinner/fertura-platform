# Modul-UI-Kit

Wiederverwendbare, deklarative CRUD-Bausteine für **Modul-Oberflächen** im
einheitlichen Bootstrap-5-Stil des Core. Verfügbar in jedem Template (Core wie
Modul) als `$this->UiKit` (`App\View\Helper\UiKitHelper`, registriert in `AppView`).

Alle Werte werden HTML-sicher ausgegeben (`h()`). Spalten-/Feld-Labels übergibt
der Aufrufer **bereits lokalisiert** (`__('…')`).

## Liste

```php
<?= $this->UiKit->index($rows, [
    ['key' => 'title',   'label' => __('Titel'), 'link' => fn($r) => ['action' => 'view', $r['id']]],
    ['key' => 'status',  'label' => __('Status'), 'badge' => fn($v) => $v === 'open' ? 'success' : 'secondary'],
    ['key' => 'active',  'label' => __('Aktiv'),  'type' => 'bool'],
    ['key' => 'created', 'label' => __('Erstellt'), 'type' => 'datetime'],
], [
    'actions' => [
        ['label' => __('Bearbeiten'), 'url' => fn($r) => ['action' => 'edit', $r['id']], 'class' => 'btn btn-sm btn-outline-primary'],
        ['label' => __('Löschen'),   'url' => fn($r) => ['action' => 'delete', $r['id']], 'class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('Wirklich löschen?')],
    ],
    'empty' => __('Keine Einträge.'),
]) ?>
```

**Spalten-Optionen:** `key`, `label`, `type` (`text|bool|datetime|badge|code`),
`link` (callable → string|array URL), `format` (callable `($value,$row)` → Text),
`badge` (callable `($value)` → Bootstrap-Variante).

## Detail

```php
<?= $this->UiKit->detail($row, [
    ['key' => 'title',  'label' => __('Titel')],
    ['key' => 'active', 'label' => __('Aktiv'), 'type' => 'bool'],
]) ?>
```

## Formular

Innerhalb eines vom Modul geöffneten `Form->create()/end()`:

```php
<?= $this->Form->create() ?>
<?= $this->UiKit->fields([
    ['key' => 'title', 'label' => __('Titel'), 'required' => true],
    ['key' => 'body',  'label' => __('Text'),  'input' => 'textarea'],
    ['key' => 'status','label' => __('Status'),'input' => 'select', 'options' => ['open' => 'offen', 'closed' => 'zu']],
]) ?>
<?= $this->Form->button(__('Speichern')) ?>
<?= $this->Form->end() ?>
```

## Einzelwert

`$this->UiKit->value($v, 'bool'|'code'|'datetime'|'badge'|'text')` formatiert einen
einzelnen Wert HTML-sicher (z. B. für eigene Templates).
