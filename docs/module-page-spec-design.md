# Modul-Page-Spec — zentraler Seiten-Renderer für Modul-Admin-Seiten

> Design-Dokument (Entwurf). Ziel: Standard-CRUD-Seiten der Module rendert der
> Core aus einer **deklarativen Seitenbeschreibung** — ein Design-Eingriff im
> Core wirkt auf alle Module, ohne dass ein Modul-Template angefasst wird.

## 1. Motivation

Heute liefert jede Modul-Web-Route ein eigenes PHP-Template
(`<modul>/templates/<tpl>.php`), das die UiKit-Bausteine selbst komponiert.
Folgen (konkret erlebt bei der `formAccordion`-Adoption, Findings B2/C des
Integrations-Audits):

- **Design-Änderungen skalieren nicht.** Ein neues Idiom (Accordion,
  Breadcrumb, Referenz-Felder) erfordert je Modul ein eigenes Release, obwohl
  sich fachlich nichts ändert.
- **Drift.** Templates kopieren einander und laufen auseinander (Chrome von
  Hand, abweichende Klassen, vergessene a11y-Attribute).
- **Boilerplate.** Eine Standard-Listenseite mit Inline-Create-Form ist zu
  ~90 % identisches Markup.

Der Core besitzt bereits die deklarative Vokabel (`UiKitHelper`:
`index/detail/fields/filters/paginate/sortHeader/bulkActions/formAccordion`,
dokumentiert in `MODULE_UI.md`) und das Prinzip „Core owns the presentation
surface" (Kap. 23.16.3). Es fehlt nur der letzte Schritt: die **Komposition**
in den Core zu holen.

## 2. Vertrag

Der Handler-Result bekommt neben `template` eine Alternative `page`. Liefert
ein Handler `page`, rendert der Core die Seite selbst; `template` bleibt
unverändert der Escape-Hatch für Spezialseiten (KB-Editor mit Live-SSE,
Ticket-Timeline, …).

```php
return [
    'title' => __d('ticketing', 'admin.queues.title'),
    'page' => [
        'sections' => [
            ['type' => 'filters',
             'fields' => [/* UiKit-filters-Spec */], 'values' => $filterValues],

            ['type' => 'table',
             'columns' => [/* UiKit-index-Spalten */], 'rows' => $rows,
             'actions' => [/* UiKit-index-Actions */],
             'select' => false,
             'paginate' => ['page' => 2, 'per_page' => 20, 'total' => 87],
             'empty' => __d('ticketing', 'admin.queues.empty')],

            ['type' => 'form_accordion',
             'title' => __d('ticketing', 'admin.queues.create'),
             'open' => $edit !== null,
             'url' => '/m/ticketing/admin/queues',
             'fields' => [/* UiKit-fields-Spec inkl. reference */],
             'submit' => __d('ticketing', 'admin.queues.save')],

            ['type' => 'alert', 'variant' => 'warning',
             'text' => __d('ticketing', 'admin.queues.need_mailbox')],

            ['type' => 'html', 'html' => $custom], // Ausweich-Slot, s. §4
        ],
    ],
];
```

Leitplanken des Vertrags:

- **Sections statt fester Seitentypen.** Eine Seite ist eine Liste von
  Sections; jede Section mappt 1:1 auf einen UiKit-Baustein. Liste +
  Inline-Form auf einer Seite (das Core-Idiom) ist damit trivial.
- **Die UiKit-Specs sind das Wire-Format.** Spalten-, Feld-, Filter- und
  Action-Definitionen aus `MODULE_UI.md` werden unverändert übernommen —
  keine zweite Vokabel, keine Übersetzungsschicht.
- **Labels kommen vorübersetzt.** Wie überall im UiKit (und beim Breadcrumb
  festgezurrt): das Modul übersetzt in seiner Domain (`__d`), der Core rendert
  Anzeige-Strings.
- **Callables sind im Spec verboten.** Die UiKit-Helper akzeptieren
  `format`/`link`/`url`-Callables — im Page-Spec sind nur Daten erlaubt
  (Coercion verwirft alles Nicht-Skalare an diesen Stellen). Wo Templates
  heute Callables nutzen, bietet der Spec deklarative Ersatzformen
  (`link_template: '/m/x/admin/things/{id}'`, Platzhalter aus Row-Keys,
  URL-encodiert ersetzt).

## 3. Rendering-Pfad im Core

1. `ModuleWebController::dispatch()`: wenn `result['page']` (Array) vorliegt →
   `PageSpecCoercer::coerce($raw)` (analog `coerceBreadcrumb`: untrusted
   shape, Allowlist je Section-Typ, unbekannte Keys/Section-Typen fallen
   stumm weg, URLs nur app-relativ). Ergebnis in `$this->set('pageSpec', …)`,
   Rendering über ein generisches Core-Template
   `templates/ModulePage/render.php`, das je Section den UiKit-Baustein
   aufruft. Admin-Shell/Standalone-Layout, Breadcrumb, Flash — alles wie
   bisher (der Spec ersetzt nur das Modul-Template, nicht die Hülle).
2. **Formulare öffnet der Core** (`Form->create()` im Renderer) → CSRF-Token
   automatisch korrekt, `url` aus dem Spec (app-relativ erzwungen).
3. **Escaping macht der UiKit** wie heute zentral (`h()` überall); der
   `html`-Slot ist die einzige Roh-Ausgabe (s. §4).

## 4. Der `html`-Slot (Ausweich-Slot) — bewusste Entscheidung nötig

Ohne Roh-Slot bricht jede Seite mit einem einzigen Sonder-Element komplett
aus dem Spec aus (zurück zum Voll-Template). Mit Roh-Slot entsteht kein
*neues* Vertrauensproblem — Modul-Templates sind heute beliebiges PHP im
selben Prozess, ein HTML-String ist strikt weniger mächtig. Empfehlung:
**zulassen**, aber im Spec-Docblock als „letztes Mittel" markieren und im
`module_lint`-Umfeld zählbar machen (Metrik: je weniger `html`-Sections,
desto besser trägt der Spec).

## 5. Versionierung & Kompatibilität

- Der Spec ist ein **langlebiger Vertrag** wie `web_routes`/`api_routes`:
  Änderungen nur additiv (E157-Disziplin). Neue Section-Typen und neue
  optionale Keys sind erlaubt; Umbenennungen/Entfernungen nicht.
- Unbekannte Section-Typen werden **stumm verworfen** (nicht 500) — ein
  Modul, das gegen einen neueren Core gebaut wurde, degradiert auf einem
  älteren Core kontrolliert (Seite ohne die neue Section), analog zur
  additiven Contract-Philosophie.
- Kein Manifest-Eintrag nötig: der Spec ist Runtime-Result des Handlers, kein
  statisches Artefakt. (`module_lint` kann ihn daher nicht prüfen; die
  Coercion zur Laufzeit ist das Gate.)

## 6. Migration & Pilot

1. **Kein Big Bang.** `template` bleibt vollwertig; bestehende Seiten laufen
   unverändert.
2. **Pilot: 1–2 Ticketing-Admin-Seiten** (z. B. `queue-groups`: Tabelle +
   Inline-Create — der Standardfall). Hand-off an die Ticketing-Session; der
   Pilot validiert den Spec gegen echte Anforderungen, BEVOR er breit
   empfohlen wird. Erst danach: `MODULE_UI.md`-Abschnitt „Page-Spec" +
   Empfehlung für neue Seiten.
3. **Messgröße:** Anteil der Modul-Admin-Seiten, die per Spec rendern
   (Ticketing allein hat ~20 Kandidaten).

## 7. Nicht-Ziele

- Kein clientseitiges Framework, kein JSON-getriebenes SPA-Rendering — der
  Spec beschreibt serverseitig gerenderte Seiten im bestehenden Stack.
- Keine Spec-Abdeckung für Spezialseiten (Editor, Timelines, Diffs) — dafür
  bleibt `template`.
- Keine Änderung am POST-Handling: der Handler verarbeitet Submits weiterhin
  selbst (Method-agnostische Route, `redirect`-Result bei Erfolg).

## 8. Entschiedene Fragen (v1, umgesetzt)

1. `html`-Slot: **zugelassen** (letztes Mittel, s. §4).
2. Section-Typ `detail`: **in v1 enthalten** (mappt 1:1 auf `UiKit->detail()`,
   vervollständigt CRUD ohne späteren Vertrags-Bump).
3. `link_template`-Platzhalter: **flach** (`{id}` = Row-Key, rawurlencodiert
   eingesetzt, Ergebnis erneut gegen `AppUrl::isSafeRelative` geprüft);
   verkettete Keys additiv, wenn ein Konsument sie braucht.
4. Coercer-Drops: **im Debug-Modus geloggt** (`Log::debug`), in Produktion
   still (kontrollierte Degradation, §5).

v1-Scope-Hinweis: Zeilen-Auswahl (`select`) + Bulk-Aktionen sind NICHT in v1 —
sie brauchen die Form-Umschließung der Tabelle und kommen additiv.

## 9. Implementierungsskizze (wenn freigegeben)

| Schritt | Inhalt |
|---|---|
| 1 | `PageSpecCoercer` (Service, reine Datenprüfung) + Unit-Tests je Section-Typ |
| 2 | `templates/ModulePage/render.php` + Section-Partials via UiKit |
| 3 | Dispatcher-Zweig `result['page']` (nach `json`, vor `template`) |
| 4 | End-to-End-Test über das `zztest_web`-Fixture (Spec-Seite rendert Tabelle + Form + CSRF) |
| 5 | `MODULE_UI.md`-Abschnitt + Hand-off Ticketing-Pilot |
