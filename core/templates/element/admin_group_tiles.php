<?php
/**
 * Builds tiles from nav groups (shared by the Module and Administration
 * landings). A group with a single item links straight to that page; a group
 * with several items links to its drill-down section. The badge shows a count
 * metric for the single-item case, otherwise the number of contained items.
 *
 * @var \App\View\AppView $this
 * @var array<string, array{label:string, items:list<array{0:string,1:string}>}> $groups
 * @var array<string, array{badge:string, detail:string}> $metrics
 */
$tiles = [];
foreach ($groups as $key => $def) {
    $items = $def['items'];
    $single = count($items) === 1;
    $names = array_map(static fn(array $it): string => (string)__($it[0]), $items);
    $metric = $single ? ($metrics[$items[0][1]] ?? null) : null;
    $tiles[] = [
        'label' => (string)__($def['label']),
        'url' => $single ? $items[0][1] : '/admin/section/' . $key,
        // Single-item group: show its metric breakdown. Multi-item group: list the
        // contained pages and badge the number of items.
        'sub' => $metric !== null && $metric['detail'] !== '' ? $metric['detail'] : implode(' · ', $names),
        'badge' => $metric !== null ? $metric['badge'] : (string)count($items),
    ];
}
echo $this->element('admin_tiles', ['tiles' => $tiles]);
