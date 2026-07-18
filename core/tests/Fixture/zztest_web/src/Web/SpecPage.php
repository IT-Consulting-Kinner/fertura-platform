<?php
declare(strict_types=1);

namespace ZztestWeb\Web;

/**
 * Fixture web handler returning a declarative PAGE SPEC instead of a template
 * (docs/module-page-spec-design.md): the Core renders the sections via
 * templates/ModulePage/render.php. Includes hostile bits (off-origin templates,
 * a callable, an unknown section type) that the coercer must drop.
 */
final class SpecPage
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        return [
            'title' => 'Spec-Seite',
            'page' => [
                'sections' => [
                    [
                        'type' => 'alert',
                        'variant' => 'warning',
                        'text' => 'Keine Mailbox konfiguriert.',
                    ],
                    [
                        'type' => 'filters',
                        'fields' => [
                            ['key' => 'q', 'label' => 'Suche', 'placeholder' => 'Name…'],
                        ],
                        'values' => ['q' => 'Sieben-Filter'],
                        'submit' => 'Filtern!',
                    ],
                    [
                        'type' => 'table',
                        'columns' => [
                            [
                                'key' => 'name',
                                'label' => 'Name',
                                'link_template' => '/m/zztest_web/things/{id}',
                                // Hostile: callables must not survive coercion.
                                'link' => static fn(array $r): string => 'https://evil.test/' . $r['id'],
                            ],
                            ['key' => 'active', 'label' => 'Aktiv', 'type' => 'bool'],
                        ],
                        'rows' => [
                            ['id' => 7, 'name' => 'Sieben', 'active' => true],
                            ['id' => 8, 'name' => 'Acht & Co', 'active' => false],
                        ],
                        'actions' => [
                            ['label' => 'Bearbeiten', 'url_template' => '/m/zztest_web/spec?edit={id}'],
                            // Hostile: off-origin url_template must be dropped.
                            ['label' => 'Evil', 'url_template' => 'https://evil.test/{id}'],
                        ],
                        'empty' => 'Nichts da.',
                        'paginate' => ['page' => 2, 'per_page' => 1, 'total' => 2],
                    ],
                    [
                        'type' => 'form_accordion',
                        'title' => 'Neu anlegen',
                        // No 'url': the form posts back to the current page.
                        'fields' => [
                            ['key' => 'name', 'label' => 'Name', 'required' => true],
                            [
                                'key' => 'mailbox_id', 'label' => 'Mailbox', 'input' => 'select',
                                'options' => ['m1' => 'Support'], 'empty' => true,
                                'reference' => ['options_url' => '/m/zztest_web/options'],
                            ],
                        ],
                        'submit' => 'Anlegen',
                    ],
                    [
                        'type' => 'detail',
                        'row' => ['name' => 'Sieben', 'active' => true],
                        'fields' => [
                            ['key' => 'name', 'label' => 'Name'],
                            ['key' => 'active', 'label' => 'Aktiv', 'type' => 'bool'],
                        ],
                    ],
                    ['type' => 'html', 'html' => '<div data-test="raw">RAW-OK</div>'],
                    // Unknown section type from a "newer" spec: dropped, no 500.
                    ['type' => 'zz_future_widget', 'payload' => 'x'],
                ],
            ],
        ];
    }
}
