<?php
/**
 * Drill-down page: one group's items rendered as tiles (e.g. "Users & groups" ->
 * Users + Groups), each with a count badge where a metric exists.
 *
 * @var \App\View\AppView $this
 * @var array{label:string, items:list<array{0:string,1:string}>} $sectionDef
 * @var array<string, int> $metrics
 * @var string $activeTop
 */
$this->assign('title', __($sectionDef['label']));
$top = ($activeTop ?? 'administration') === 'module' ? 'module' : 'administration';
$backUrl = '/admin/' . $top;
$backLabel = $top === 'module' ? __('admin.nav.modules') : __('admin.nav.administration');

$tiles = [];
foreach ($sectionDef['items'] as $item) {
    $tiles[] = [
        'label' => (string)__($item[0]),
        'url' => $item[1],
        'badge' => isset($metrics[$item[1]]) ? (string)$metrics[$item[1]] : '',
    ];
}
?>
<nav aria-label="breadcrumb" class="mb-2">
    <a class="small text-decoration-none" href="<?= h($backUrl) ?>">&larr; <?= h($backLabel) ?></a>
</nav>
<h1 class="h3 mb-4"><?= h(__($sectionDef['label'])) ?></h1>
<?= $this->element('admin_tiles', compact('tiles')) ?>
