<?php
/**
 * Builds tiles from nav groups (shared by the Module and Administration
 * landings). A group with a single item links straight to that page; a group
 * with several items links to its drill-down section. The badge shows a count
 * metric for the single-item case, otherwise the number of contained items.
 *
 * @var \App\View\AppView $this
 * @var array<string, array{label:string, items:list<array{0:string,1:string}>}> $groups
 * @var array<string, int> $metrics
 */
$tiles = [];
foreach ($groups as $key => $def) {
    $items = $def['items'];
    $single = count($items) === 1;
    $names = array_map(static fn(array $it): string => (string)__($it[0]), $items);
    $badge = $single && isset($metrics[$items[0][1]])
        ? (string)$metrics[$items[0][1]]
        : (string)count($items);
    $tiles[] = [
        'label' => (string)__($def['label']),
        'url' => $single ? $items[0][1] : '/admin/section/' . $key,
        'sub' => implode(' · ', $names),
        'badge' => $badge,
    ];
}
echo $this->element('admin_tiles', ['tiles' => $tiles]);
