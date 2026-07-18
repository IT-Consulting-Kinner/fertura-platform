<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\UiKitHelper;
use Cake\TestSuite\TestCase;
use Cake\View\View;

/**
 * Tests the module UI kit (declarative CRUD building blocks): rendering + HTML escaping.
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

        $this->assertStringContainsString('<th scope="col">Name</th>', $html);
        $this->assertStringContainsString('Alpha', $html);
        // XSS: raw HTML from data must be escaped.
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // bool type -> badge.
        $this->assertStringContainsString('text-bg-success', $html);
        $this->assertStringContainsString('text-bg-secondary', $html);
    }

    public function testConfirmPostEscapesConfirmTextInDataAttribute(): void
    {
        // The confirm text may carry caller data (e.g. a module display name). It
        // must be HTML-escaped inside the data-confirm attribute so a quote cannot
        // break out of the attribute and inject markup. The FormHelper escapes
        // attribute values; this locks that in. It also guards the inverse: an extra
        // h() in confirmPost would DOUBLE-escape (the &amp;quot; assertion catches that).
        $html = $this->ui->confirmPost('Delete', '/admin/things/delete', 'Drop "<b>x</b>"?');

        $this->assertStringContainsString('data-confirm', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html); // not raw markup
        $this->assertStringContainsString('&lt;b&gt;', $html); // escaped exactly once
        $this->assertStringContainsString('&quot;', $html); // quote encoded -> cannot break out
        $this->assertStringNotContainsString('&amp;quot;', $html); // NOT double-escaped
    }

    public function testFormAccordionRendersCollapsedByDefaultWithUniqueIds(): void
    {
        $first = $this->ui->formAccordion('Neu anlegen', '<form id="f1"></form>');
        $second = $this->ui->formAccordion('Bearbeiten', '<form id="f2"></form>');

        // Collapsed by default: list first, Bootstrap toggles via the bundle JS.
        $this->assertStringContainsString('accordion-button collapsed', $first);
        $this->assertStringContainsString('aria-expanded="false"', $first);
        $this->assertStringContainsString('data-bs-toggle="collapse"', $first);
        $this->assertStringContainsString('class="accordion-collapse collapse"', $first);
        // Body HTML is caller-produced and passes through RAW.
        $this->assertStringContainsString('<form id="f1"></form>', $first);

        // Auto ids stay unique across multiple accordions on one page, and the
        // toggle targets exactly its own body.
        preg_match('/id="(uikit-accordion-\d+)"/', $first, $m1);
        preg_match('/id="(uikit-accordion-\d+)"/', $second, $m2);
        $this->assertNotSame($m1[1], $m2[1]);
        $this->assertStringContainsString('data-bs-target="#' . $m1[1] . '-body"', $first);
        $this->assertStringContainsString('aria-controls="' . $m1[1] . '-body"', $first);
    }

    public function testFormAccordionOpenRendersExpandedAndEscapesTitle(): void
    {
        // Edit mode ('open') must not hide its own prefilled form behind a fold.
        $html = $this->ui->formAccordion('Edit "<b>x</b>"', '<form></form>', ['open' => true, 'id' => 'acc-edit']);

        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('accordion-collapse collapse show', $html);
        $this->assertStringNotContainsString('accordion-button collapsed', $html);
        $this->assertStringContainsString('id="acc-edit"', $html);
        $this->assertStringContainsString('data-bs-target="#acc-edit-body"', $html);
        // Title is data -> escaped; body stays raw.
        $this->assertStringNotContainsString('<b>x</b>', str_replace('<form></form>', '', $html));
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testFormAccordionEscapesCallerSuppliedId(): void
    {
        // Adversarial-review finding: pin the h() escaping of the 'id' option —
        // it lands in FOUR attribute contexts (id, data-bs-target, aria-controls,
        // body id). Without this test, dropping the h() calls ("auto ids are
        // always safe") would pass the suite; a later caller building the id from
        // record data could then break out of the attribute (same evolution that
        // made confirmPost's data-attribute test necessary).
        $html = $this->ui->formAccordion('T', '<form></form>', ['id' => 'x"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html); // quote cannot break out
        $this->assertStringNotContainsString('&amp;quot;', $html); // NOT double-escaped
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
        $columns = [['key' => 'name', 'label' => 'Name', 'link' => static fn($r) => '/things/' . $r['id']]];
        $actions = [['label' => 'Öffnen', 'url' => static fn($r) => '/things/' . $r['id'], 'class' => 'btn btn-sm']];
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
        // Currently ascending by 'name' -> link switches to desc, arrow up.
        $asc = $this->ui->sortHeader('Name', 'name', ['sort' => 'name', 'dir' => 'asc'], '/admin/tenants');
        $this->assertStringContainsString('dir=desc', $asc);
        $this->assertStringContainsString('sort=name', $asc);
        $this->assertStringContainsString('↑', $asc);

        // Different column -> default asc, no arrow.
        $other = $this->ui->sortHeader('Schlüssel', 'key', ['sort' => 'name', 'dir' => 'asc'], '/admin/tenants');
        $this->assertStringContainsString('sort=key', $other);
        $this->assertStringContainsString('dir=asc', $other);
        $this->assertStringNotContainsString('↑', $other);
    }

    public function testPaginateRendersWindowAndHidesForSinglePage(): void
    {
        $this->assertSame('', $this->ui->paginate(1, 20, 15, '/x'), 'eine Seite -> keine Paginierung');

        $html = $this->ui->paginate(3, 10, 100, '/admin/tenants'); // 10 pages, currently 3
        $this->assertStringContainsString('pagination', $html);
        $this->assertStringContainsString('page=4', $html); // next
        $this->assertStringContainsString('page=2', $html); // previous
        $this->assertStringContainsString('active', $html); // current page marked
    }

    public function testSelectColumnAndBulkActions(): void
    {
        $rows = [['id' => 'a1', 'name' => 'Alpha'], ['id' => 'b2', 'name' => 'Beta']];
        $html = $this->ui->index($rows, [['key' => 'name', 'label' => 'Name']], ['select' => true, 'idKey' => 'id']);
        // Selection checkboxes per row carrying the ID.
        $this->assertStringContainsString('name="ids[]" value="a1"', $html);
        $this->assertStringContainsString('name="ids[]" value="b2"', $html);

        $bar = $this->ui->bulkActions([
            ['value' => 'activate', 'label' => 'Aktivieren'],
            ['value' => 'suspend', 'label' => 'Suspendieren', 'confirm' => 'Sicher?'],
        ]);
        $this->assertStringContainsString('name="op" value="activate"', $bar);
        $this->assertStringContainsString('value="suspend"', $bar);
        // Destructive bulk action routes through the shared Bootstrap confirm modal
        // (data-confirm, handled by ui.js) instead of native window.confirm().
        $this->assertStringContainsString('data-confirm="Sicher?"', $bar);
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
