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
  die Area halten (Sichtbarkeit = serverseitige Autorisierung).
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
