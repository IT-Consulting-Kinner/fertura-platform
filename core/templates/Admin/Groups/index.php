<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $groups
 * @var bool $openCreate   Expand the create accordion (after a failed create).
 */
$open = !empty($openCreate);
?>
<h1 class="h3 mb-3"><?= h(__('admin.groups.title')) ?></h1>

<div class="accordion mb-4" id="groupCreate">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button<?= $open ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#createGroup" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="createGroup">
                <?= h(__('admin.groups.new')) ?>
            </button>
        </h2>
        <div id="createGroup" class="accordion-collapse collapse<?= $open ? ' show' : '' ?>">
            <div class="accordion-body" style="max-width:560px">
                <?= $this->Form->create(null, ['url' => ['action' => 'add']]) ?>
                <div class="mb-3"><label class="form-label"><?= h(__('admin.groups.field_name')) ?></label><?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'required' => true]) ?></div>
                <div class="mb-3"><label class="form-label"><?= h(__('admin.groups.field_description')) ?></label><?= $this->Form->control('description', ['label' => false, 'type' => 'textarea', 'class' => 'form-control', 'rows' => 2]) ?></div>
                <?= $this->Form->button(__('admin.groups.add_submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.groups.col_name')) ?></th><th scope="col"><?= h(__('admin.groups.col_description')) ?></th><th scope="col"><?= h(__('admin.groups.col_members')) ?></th><th scope="col"><?= h(__('admin.groups.col_status')) ?></th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
        <tr>
            <td><?= h($g['name']) ?></td>
            <td class="text-muted"><?= h($g['description']) ?: '–' ?></td>
            <td><?= (int)$g['member_count'] ?></td>
            <td><?php $a = filter_var($g['active'], FILTER_VALIDATE_BOOLEAN); ?>
                <span class="badge text-bg-<?= $a ? 'success' : 'secondary' ?>"><?= h($a ? __('admin.groups.active') : __('admin.groups.inactive')) ?></span></td>
            <td class="text-end"><?= $this->Html->link(__('admin.groups.manage'), ['action' => 'view', $g['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($groups === []): ?><tr><td colspan="5" class="text-muted"><?= h(__('admin.groups.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
