<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Module;

use App\Service\Module\PageSpecCoercer;
use Cake\TestSuite\TestCase;
use stdClass;

/**
 * Unit tests for the page-spec coercion (docs/module-page-spec-design.md):
 * only data crosses the boundary, URLs/templates fail closed, unknown shapes
 * degrade silently.
 */
class PageSpecCoercerTest extends TestCase
{
    private PageSpecCoercer $coercer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coercer = new PageSpecCoercer();
    }

    public function testUnknownSectionTypesAndNonArraysAreDropped(): void
    {
        $out = $this->coercer->coerce(['sections' => [
            ['type' => 'zz_future_widget', 'x' => 1],
            'not-an-array',
            ['type' => 'alert', 'text' => 'ok'],
        ]]);

        $this->assertCount(1, $out['sections']);
        $this->assertSame('alert', $out['sections'][0]['type']);
    }

    public function testTableDropsCallablesForeignTemplatesAndNonScalarCells(): void
    {
        $out = $this->coercer->coerce(['sections' => [[
            'type' => 'table',
            'columns' => [[
                'key' => 'name',
                'label' => 'Name',
                'link_template' => 'https://evil.test/{id}', // off-origin -> dropped
                'link' => static fn(): string => 'x', // callable -> not in allowlist
                'format' => static fn(): string => 'y', // callable -> not in allowlist
            ]],
            'rows' => [['name' => 'A', 'nested' => ['x'], 'cb' => static fn() => 1]],
            'actions' => [
                ['label' => 'Edit', 'url_template' => '/x/{id}'],
                ['label' => 'Evil', 'url_template' => '/\\evil.example/{id}'], // WHATWG bypass -> dropped
            ],
        ]]]);

        $table = $out['sections'][0];
        $this->assertArrayNotHasKey('link_template', $table['columns'][0]);
        $this->assertArrayNotHasKey('link', $table['columns'][0]);
        $this->assertArrayNotHasKey('format', $table['columns'][0]);
        $this->assertSame(['name' => 'A'], $table['rows'][0]); // nested + callable cells dropped
        $this->assertCount(1, $table['actions']);
        $this->assertSame('/x/{id}', $table['actions'][0]['url_template']);
    }

    public function testTableWithoutColumnsIsDropped(): void
    {
        $out = $this->coercer->coerce(['sections' => [['type' => 'table', 'rows' => [['a' => 1]]]]]);
        $this->assertSame([], $out['sections']);
    }

    public function testFormAccordionDropsUnsafeUrlAndKeepsFields(): void
    {
        $out = $this->coercer->coerce(['sections' => [[
            'type' => 'form_accordion',
            'title' => 'Neu',
            'url' => 'https://evil.test/submit',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'required' => true],
                ['key' => '', 'label' => 'kaputt'], // empty key -> dropped
                [
                    'key' => 'ref', 'input' => 'select',
                    'reference' => ['url' => '//evil.test', 'options_url' => '/ok/options', 'area' => 'a'],
                ],
            ],
        ]]]);

        $acc = $out['sections'][0];
        $this->assertArrayNotHasKey('url', $acc); // unsafe -> form posts back to current page
        $this->assertCount(2, $acc['fields']);
        // reference: unsafe url dropped, safe options_url + area kept.
        $this->assertSame(
            ['options_url' => '/ok/options', 'area' => 'a'],
            $acc['fields'][1]['reference'],
        );
    }

    public function testTableActionKeepsPostFlagAndFormAccordionKeepsHiddenMap(): void
    {
        // v1.1 additions from the Ticketing pilot: per-row POST actions and
        // first-class hidden dispatch fields.
        $out = $this->coercer->coerce(['sections' => [
            [
                'type' => 'table',
                'columns' => [['key' => 'name']],
                'rows' => [],
                'actions' => [[
                    'label' => 'Deaktivieren', 'url_template' => '/x/toggle/{id}',
                    'post' => 1, 'variant' => 'btn-warning', 'confirm' => 'Sicher?',
                ]],
            ],
            [
                'type' => 'form_accordion',
                'title' => 'Neu',
                'hidden' => ['action' => 'create', 'cb' => static fn() => 1, 'nested' => ['x']],
                'fields' => [['key' => 'name']],
            ],
        ]]);

        $action = $out['sections'][0]['actions'][0];
        $this->assertTrue($action['post']); // cast to bool
        $this->assertSame('btn-warning', $action['variant']);
        $this->assertSame('Sicher?', $action['confirm']);
        // hidden map: scalars only, callables/nested dropped.
        $this->assertSame(['action' => 'create'], $out['sections'][1]['hidden']);
    }

    public function testFiltersKeepsValuesAndDropsUnsafeUrlAndNonScalars(): void
    {
        $out = $this->coercer->coerce(['sections' => [[
            'type' => 'filters',
            'fields' => [['key' => 'q', 'label' => 'Suche', 'placeholder' => 'Name…']],
            'values' => ['q' => 'abc', 'cb' => static fn() => 1, 'nested' => ['x']],
            'submit' => 'Los',
            'url' => 'https://evil.test/search',
        ]]]);

        $f = $out['sections'][0];
        $this->assertSame([['key' => 'q', 'label' => 'Suche', 'placeholder' => 'Name…']], $f['fields']);
        $this->assertSame(['q' => 'abc'], $f['values']); // callable + nested dropped
        $this->assertSame('Los', $f['submit']);
        $this->assertArrayNotHasKey('url', $f); // off-origin -> form targets current page
    }

    public function testHtmlSectionDropsNonScalarValueInsteadOfThrowing(): void
    {
        // Review finding: (string) on an object without __toString would throw
        // out of the coercer, breaking the "degrade silently" contract.
        $out = $this->coercer->coerce(['sections' => [
            ['type' => 'html', 'html' => new stdClass()],
            ['type' => 'html', 'html' => ['x']],
            ['type' => 'html', 'html' => 'ok'],
        ]]);

        $this->assertCount(1, $out['sections']);
        $this->assertSame('ok', $out['sections'][0]['html']);
    }

    public function testAlertVariantFallsBackToInfoOnUnknownValue(): void
    {
        $out = $this->coercer->coerce(['sections' => [
            ['type' => 'alert', 'variant' => 'evil"><script>', 'text' => 'T'],
        ]]);

        $this->assertSame('info', $out['sections'][0]['variant']);
    }

    public function testExpandTemplateEncodesValuesAndFailsClosed(): void
    {
        // Values are rawurlencode'd: a hostile cell value cannot smuggle an authority.
        $this->assertSame(
            '/x/..%2F..%2Fetc',
            PageSpecCoercer::expandTemplate('/x/{id}', ['id' => '../../etc']),
        );
        $this->assertSame(
            '/x/a%5Cb',
            PageSpecCoercer::expandTemplate('/x/{id}', ['id' => 'a\\b']),
        );
        // Missing key -> empty substitution, still a safe path.
        $this->assertSame('/x/', PageSpecCoercer::expandTemplate('/x/{nope}', []));
        // A template that is unsafe even before substitution fails closed.
        $this->assertNull(PageSpecCoercer::expandTemplate('//evil.test/{id}', ['id' => '1']));
    }
}
