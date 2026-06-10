<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\UiKitHelper;
use Cake\View\View;
use Cake\TestSuite\TestCase;

/**
 * Test des Modul-UI-Kits (deklarative CRUD-Bausteine): Rendering + HTML-Escaping.
 */
class UiKitHelperTest extends TestCase
{
    private UiKitHelper $ui;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ui = new UiKitHelper(new View());
    }

    public function testIndexRendersHeadersRowsAndEscapes(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alpha', 'active' => true],
            ['id' => 2, 'name' => '<script>x</script>', 'active' => false],
        ];
        $columns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'active', 'label' => 'Aktiv', 'type' => 'bool'],
        ];
        $html = $this->ui->index($rows, $columns);

        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('Alpha', $html);
        // XSS: Roh-HTML aus Daten muss escapt sein.
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // bool-Typ -> Badge.
        $this->assertStringContainsString('text-bg-success', $html);
        $this->assertStringContainsString('text-bg-secondary', $html);
    }

    public function testIndexEmptyState(): void
    {
        $html = $this->ui->index([], [['key' => 'a', 'label' => 'A']], ['empty' => 'Nichts da']);
        $this->assertStringContainsString('Nichts da', $html);
        $this->assertStringContainsString('colspan="1"', $html);
    }

    public function testIndexCellLinkAndActions(): void
    {
        $rows = [['id' => 7, 'name' => 'Sieben']];
        $columns = [['key' => 'name', 'label' => 'Name', 'link' => static fn ($r) => '/things/' . $r['id']]];
        $actions = [['label' => 'Öffnen', 'url' => static fn ($r) => '/things/' . $r['id'], 'class' => 'btn btn-sm']];
        $html = $this->ui->index($rows, $columns, ['actions' => $actions]);

        $this->assertStringContainsString('href="/things/7"', $html);
        $this->assertStringContainsString('>Sieben</a>', $html);
        $this->assertStringContainsString('Öffnen', $html);
    }

    public function testValueFormatting(): void
    {
        $this->assertStringContainsString('text-bg-success', $this->ui->value(true, 'bool'));
        $this->assertSame('<code class="small">x&lt;y</code>', $this->ui->value('x<y', 'code'));
        $this->assertSame('&lt;b&gt;', $this->ui->value('<b>', 'text'));
    }

    public function testSortHeaderTogglesDirection(): void
    {
        // Aktuell nach 'name' aufsteigend -> Link schaltet auf desc, Pfeil hoch.
        $asc = $this->ui->sortHeader('Name', 'name', ['sort' => 'name', 'dir' => 'asc'], '/admin/tenants');
        $this->assertStringContainsString('dir=desc', $asc);
        $this->assertStringContainsString('sort=name', $asc);
        $this->assertStringContainsString('↑', $asc);

        // Andere Spalte -> default asc, kein Pfeil.
        $other = $this->ui->sortHeader('Schlüssel', 'key', ['sort' => 'name', 'dir' => 'asc'], '/admin/tenants');
        $this->assertStringContainsString('sort=key', $other);
        $this->assertStringContainsString('dir=asc', $other);
        $this->assertStringNotContainsString('↑', $other);
    }

    public function testPaginateRendersWindowAndHidesForSinglePage(): void
    {
        $this->assertSame('', $this->ui->paginate(1, 20, 15, '/x'), 'eine Seite -> keine Paginierung');

        $html = $this->ui->paginate(3, 10, 100, '/admin/tenants'); // 10 Seiten, aktuell 3
        $this->assertStringContainsString('pagination', $html);
        $this->assertStringContainsString('page=4', $html); // nächste
        $this->assertStringContainsString('page=2', $html); // vorige
        $this->assertStringContainsString('active', $html);  // aktuelle Seite markiert
    }

    public function testSelectColumnAndBulkActions(): void
    {
        $rows = [['id' => 'a1', 'name' => 'Alpha'], ['id' => 'b2', 'name' => 'Beta']];
        $html = $this->ui->index($rows, [['key' => 'name', 'label' => 'Name']], ['select' => true, 'idKey' => 'id']);
        // Auswahl-Checkboxen je Zeile mit der ID.
        $this->assertStringContainsString('name="ids[]" value="a1"', $html);
        $this->assertStringContainsString('name="ids[]" value="b2"', $html);

        $bar = $this->ui->bulkActions([
            ['value' => 'activate', 'label' => 'Aktivieren'],
            ['value' => 'suspend', 'label' => 'Suspendieren', 'confirm' => 'Sicher?'],
        ]);
        $this->assertStringContainsString('name="op" value="activate"', $bar);
        $this->assertStringContainsString('value="suspend"', $bar);
        $this->assertStringContainsString('confirm(', $bar); // Confirm-Dialog
    }

    public function testDetailRendersDefinitionList(): void
    {
        $html = $this->ui->detail(
            ['name' => 'Alpha', 'active' => false],
            [['key' => 'name', 'label' => 'Name'], ['key' => 'active', 'label' => 'Aktiv', 'type' => 'bool']],
        );
        $this->assertStringContainsString('<dt class="col-sm-3">Name</dt>', $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('text-bg-secondary', $html);
    }
}
