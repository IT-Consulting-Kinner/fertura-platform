# Lizenzierung

## Geltungsbereich der AGPL-3.0: ausschließlich der Core

Die **GNU Affero General Public License v3.0 (AGPL-3.0)** (`LICENSE`) gilt
**ausschließlich für den Core** der Fertura-Plattform — also die
technische Plattform selbst (Kapitel 23.3 des Plattform-Dokuments).

Begründung: AGPL schützt den offenen Core gegen geschlossene
(Cloud-)Forks — wer den Core als Netzwerkdienst anbietet, muss seine
Änderungen am Core offenlegen. Das passt zur Souveränitäts- und
Open-Core-Positionierung.

## Ausnahme für alles außerhalb des Core (Section 7 AGPL-3.0)

> **Hinweis:** Entwurf. Die genaue Formulierung ist vor einem offiziellen
> Release juristisch zu prüfen.

**Alle installierbaren Einheiten außerhalb des Core sind von der AGPL
ausgenommen.** Das umfasst insbesondere:

- **Main-Module** (z. B. Ticketing, Wissensdatenbank, CRM),
- **Extension-Module** jeglicher Art — reguläre Extension-Module und
  Integrations-Extension-Module (Kapitel 23.4 / 23.5).

Als zusätzliche Erlaubnis gemäß Abschnitt 7 der GNU AGPL-3.0 gewähren die
Rechteinhaber die Erlaubnis, solche Einheiten zu entwickeln, zu
verbreiten und unter Bedingungen eigener Wahl (einschließlich
proprietärer Bedingungen) zu lizenzieren, sofern sie ausschließlich über
die veröffentlichten Erweiterungsschnittstellen der Plattform mit dem
Core und untereinander interagieren:

- Contracts (Resolver, Collector, Event),
- Service-Contracts / öffentliche Modul-Interfaces,
- die zugehörigen Capability-Handles und Registrierungsmechanismen gemäß
  Plattform-Spezifikation.

Das bloße Laden, Kombinieren oder die In-Process-Ausführung solcher
Einheiten zusammen mit dem Core bewirkt **nicht**, dass sie der AGPL
unterliegen.

Diese Erlaubnis erlaubt **nicht**, den AGPL-lizenzierten Quellcode des
Core selbst unter abweichenden (nicht-AGPL-)Bedingungen zu verändern oder
zu verbreiten. Änderungen am Core bleiben vollständig der AGPL-3.0
unterworfen.

### English summary

The AGPL-3.0 (`LICENSE`) applies **only to the Core** of the Fertura
platform. All installable units outside the Core — Main Modules and
Extension Modules of any kind (regular and integration) — are exempt. As
an additional permission under section 7 of the GNU AGPL-3.0, the
copyright holders grant permission to develop, distribute, and license
such units under terms of your choice (including proprietary terms),
provided they interact with the Core and with each other solely through
the platform's published extension interfaces (Contracts, Service
contracts / public module interfaces, and the associated Capability
handles and registration mechanisms). Merely loading, combining, or
running such units in-process with the Core does not make them subject to
the AGPL. This permission does not allow relicensing the Core's own
AGPL-covered source.

## Abgrenzung: Software-Lizenz vs. Modul-Lizenzierung

Die hier beschriebene **Software-Lizenz** (AGPL-3.0 für den Core +
Ausnahme für alles übrige) ist zu unterscheiden von der **kommerziellen
Modul-Lizenzierung** der Plattform (Lizenzschlüssel, Marketplace,
Aktivierung) gemäß Plattform-Anforderungsdokument Kap. 23.9 / 24.8 / 28.7:

- Die **Software-Lizenz** regelt die urheberrechtliche Nutzung des
  Quellcodes.
- Die **Modul-Lizenzierung** ist ein Produkt-Feature zur kommerziellen
  Freischaltung von Modulen zur Laufzeit.

## Entscheidung

- **Nur der Core** steht unter **AGPL-3.0**.
- **Alles außerhalb des Core** (Main-Module und Extension-Module jeder
  Art) ist über die **Section-7-Zusatzerlaubnis** ausgenommen und darf
  unter beliebigen, auch proprietären Bedingungen lizenziert werden.
- Status: Entwurf; juristische Prüfung vor Release ausstehend.
