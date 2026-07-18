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

### Inline-Accordion (Liste zuerst, Formular eingeklappt)

Das Core-Admin-Idiom für Anlege-/Bearbeitungsformulare auf Listenseiten:
`formAccordion($titel, $bodyHtml, $options)` rendert das Bootstrap-Accordion-
Chrome, damit kein Modul es je Template von Hand baut. `$bodyHtml` ist
fertiges HTML (typisch das komplette Formular von oben) und wird **roh**
ausgegeben; der Titel wird escaped. Das nötige Collapse-JS
(`bootstrap.bundle.min`) laden Admin-Shell und Modul-Layout bereits.

```php
<?php ob_start(); ?>
<?= $this->Form->create() ?>
<?= $this->UiKit->fields([/* wie oben */]) ?>
<?= $this->Form->button(__('Speichern')) ?>
<?= $this->Form->end() ?>
<?= $this->UiKit->formAccordion(__('Neu anlegen'), (string)ob_get_clean(), [
    // 'open' => true  — z. B. im Edit-Modus oder nach Validierungsfehlern,
    // damit sich das vorbefüllte Formular nicht selbst versteckt.
    'open' => (bool)($edit ?? false),
]) ?>
```

Standard ist eingeklappt (`open => false`) — die Liste bleibt zuerst sichtbar.
Mehrere Accordions pro Seite bekommen automatisch eindeutige IDs; ein eigenes
`'id'` ist nur nötig, wenn ein stabiler Anker gebraucht wird.

### Referenz-Felder (Elemente, die woanders verwaltet werden)

Ein `select`, dessen Einträge auf einer anderen Admin-Seite gepflegt werden
(gleiches oder anderes Modul), bekommt per `reference` eine Input-Group mit
„In neuem Tab öffnen"-Link und „Auswahl aktualisieren"-Button — der Nutzer
muss die Form nicht verlassen, wenn ein Element fehlt:

```php
['key' => 'mailbox_id', 'label' => __('Mailbox'), 'input' => 'select',
 'options' => $mailboxes, 'empty' => true,
 'reference' => [
     'url'         => '/m/ticketing/admin/mailboxes',          // Anlage/Übersicht (neuer Tab, rel=noopener)
     'area'        => 'ticketing_admin',                       // Link nur, wenn der Nutzer diese Area hält
     'options_url' => '/m/ticketing/admin/mailboxes/options',  // JSON-Refresh (s. u.)
 ]]
```

Regeln:

- Beide URLs müssen **app-relativ** sein (führendes `/`, kein `//`) — sonst
  werden sie verworfen.
- `area` ist optional; wenn gesetzt, erscheint der Link nur für Nutzer, die
  die Area halten (Sichtbarkeit = serverseitige Autorisierung). Die
  Area-Information liegt nur auf **Admin-Seiten** vor — auf Standalone-Seiten
  (`auth=user` ohne `area`) wird ein area-gegateter Link daher immer
  ausgeblendet (fail-closed). Für Standalone-Seiten `area` weglassen.
- Der Refresh baut die Optionen per `ui.js` in place neu auf; die aktuelle
  Auswahl und eine führende Leer-Option („bitte wählen") bleiben erhalten.

**Options-Endpunkt:** eine normale `web_route` des Ziel-Moduls, deren Handler
statt eines Templates `json` zurückgibt — Session-Auth, Tenant-Gates und
RLS-Kontext gelten wie auf jeder Web-Seite:

```php
public function handle(array $request): array
{
    return ['json' => ['options' => [
        ['value' => (string)$row['id'], 'label' => $row['name']],
        // …
    ]]];
}
```

## Einzelwert

`$this->UiKit->value($v, 'bool'|'code'|'datetime'|'badge'|'text')` formatiert einen
einzelnen Wert HTML-sicher (z. B. für eigene Templates).

## Seiten-Spec (zentraler Renderer, empfohlen für Standard-CRUD)

Statt eines eigenen Templates kann ein Web-Handler eine **deklarative
Seitenbeschreibung** zurückgeben — der Core rendert sie selbst (einheitliches
Design, ein Core-Eingriff wirkt auf alle Module). Vollständiges Design:
`docs/module-page-spec-design.md`.

```php
return [
    'title' => __d('meinmodul', 'admin.things.title'),
    'page' => ['sections' => [
        ['type' => 'alert', 'variant' => 'warning', 'text' => __d('meinmodul', 'admin.things.hint')],
        ['type' => 'filters', 'fields' => [/* wie filters() */], 'values' => $filterValues],
        ['type' => 'table',
         'columns' => [
             ['key' => 'name', 'label' => __d('meinmodul', 'f.name'),
              'link_template' => '/m/meinmodul/things/{id}'],   // statt link-Callable
             ['key' => 'active', 'label' => __d('meinmodul', 'f.active'), 'type' => 'bool'],
         ],
         'rows' => $rows,
         'actions' => [
             // GET-Link; Query-Strings in Templates sind erlaubt:
             ['label' => __d('meinmodul', 'a.edit'),
              'url_template' => '/m/meinmodul/admin/things?edit={id}'],
             // Per-Zeile-POST (Toggle-Idiom): Inline-Form, CSRF vom Core,
             // mit 'confirm' über das geteilte Bestätigungs-Modal:
             ['label' => __d('meinmodul', 'a.deactivate'),
              'url_template' => '/m/meinmodul/admin/things/toggle/{id}',
              'post' => true, 'confirm' => __d('meinmodul', 'a.deactivate_confirm')],
         ],
         'empty' => __d('meinmodul', 'admin.things.empty'),
         'paginate' => ['page' => $page, 'per_page' => 20, 'total' => $total]],
        ['type' => 'form_accordion',
         'title' => __d('meinmodul', 'admin.things.create'),
         'open' => $edit !== null,
         'url' => '/m/meinmodul/admin/things',
         'hidden' => ['action' => 'create'],   // Dispatch-Felder ohne sichtbaren Wrapper
         'fields' => [/* wie fields(), inkl. reference */],
         'submit' => __d('meinmodul', 'a.save')],
        ['type' => 'detail', 'row' => $row, 'fields' => [/* wie detail() */]],
        ['type' => 'html', 'html' => $sonderfall], // letztes Mittel
    ]],
];
```

Regeln:

- **Nur Daten, keine Callables.** `link`/`format`/`badge`/`url`-Callables werden
  verworfen; Links kommen aus `link_template`/`url_template` mit flachen
  `{key}`-Platzhaltern (Werte werden URL-encodiert eingesetzt).
- **URLs/Templates nur app-relativ** — alles andere fällt weg (fail-closed).
- **Unbekannte Section-Typen/Keys werden still verworfen** (im Debug-Modus
  geloggt): eine Spec gegen einen neueren Core degradiert kontrolliert.
- Das Formular öffnet der **Core** → CSRF automatisch; `submit`-Label kommt vom
  Modul (Default: „Speichern").
- `template` bleibt vollwertig für Spezialseiten (Editor, Timeline, …);
  `html`-Sections sind das letzte Mittel innerhalb einer Spec.
- Noch nicht enthalten (kommt additiv): Zeilen-Auswahl + Bulk-Aktionen.

**`page` NEBEN `vars`/`template` (Abwärtskompatibilität):** Ein Handler darf
`page` UND `template`/`vars` gleichzeitig liefern — ein Page-Spec-fähiger Core
rendert `page`, ein älterer Core ignoriert das unbekannte Feld und rendert das
Manifest-`template` wie bisher. So pilotiert ein Modul den Spec ohne
`core_compatibility`-Anhebung; das Template wird erst gelöscht (und
`core_compatibility` angehoben), wenn das Modul den Spec verbindlich macht.
