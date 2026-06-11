<?php
/**
 * @var \App\View\AppView $this
 * @var string $q
 * @var list<array<string,mixed>> $results
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.search.title')) ?></h1>
    <?php if (in_array('core_config', $userAreas ?? [], true)): ?>
        <?= $this->Form->postLink(
            __('admin.search.reindex'),
            ['action' => 'reindex'],
            ['class' => 'btn btn-sm btn-outline-secondary', 'confirm' => __('admin.search.reindex_confirm')],
        ) ?>
    <?php endif; ?>
</div>

<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 mb-3']) ?>
    <div class="col flex-grow-1">
        <?= $this->Form->control('q', [
            'label' => false,
            'value' => $q,
            'class' => 'form-control',
            'placeholder' => __('admin.search.placeholder'),
        ]) ?>
    </div>
    <div class="col-auto"><?= $this->Form->button(__('admin.search.btn'), ['class' => 'btn btn-primary']) ?></div>
<?= $this->Form->end() ?>

<?php if (trim($q) !== ''): ?>
    <?= $this->UiKit->index($results, [
        ['key' => 'title', 'label' => __('admin.search.col_title'), 'link' => fn ($r) => $r['url'] ?: null],
        ['key' => 'source', 'label' => __('admin.search.col_source'), 'type' => 'code'],
        ['key' => 'entity_type', 'label' => __('admin.search.col_type')],
        ['key' => 'score', 'label' => __('admin.search.col_score')],
    ], ['empty' => __('admin.search.empty')]) ?>
<?php endif; ?>
