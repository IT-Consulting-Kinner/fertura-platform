<?php
/**
 * @var \App\View\AppView $this
 * @var string $heading
 * @var array<string, array{label:string, items:list<array{0:string,1:string}>}> $groups
 * @var array<string, int> $metrics
 */
$this->assign('title', __($heading));
?>
<h1 class="h3 mb-4"><?= h(__($heading)) ?></h1>
<?= $this->element('admin_group_tiles', ['groups' => $groups, 'metrics' => $metrics]) ?>
