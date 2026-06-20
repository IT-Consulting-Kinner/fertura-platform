<?php
/**
 * Drill-down page: one group's items rendered as tiles (e.g. "Users & groups" ->
 * Users + Groups), each with a count badge where a metric exists.
 *
 * @var \App\View\AppView $this
 * @var array{label:string, items:list<array{0:string,1:string}>} $sectionDef
 * @var array<string, array{badge:string, detail:string}> $metrics
 * @var string $activeTop
 */
$this->assign('title', __($sectionDef['label']));

$tiles = [];
foreach ($sectionDef['items'] as $item) {
    $metric = $metrics[$item[1]] ?? null;
    $tiles[] = [
        'label' => (string)__($item[0]),
        'url' => $item[1],
        'sub' => $metric !== null ? $metric['detail'] : '',
        'badge' => $metric !== null ? $metric['badge'] : '',
    ];
}
?>
<h1 class="h3 mb-4"><?= h(__($sectionDef['label'])) ?></h1>
<?= $this->element('admin_tiles', compact('tiles')) ?>
