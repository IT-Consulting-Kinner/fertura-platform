<?php
/**
 * @var \App\View\AppView $this
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.groups.add_title')) ?></h1>
    <?= $this->Html->link('&laquo; ' . __('admin.groups.backtolist'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>
<div class="card"><div class="card-body" style="max-width:520px">
    <?= $this->Form->create(null) ?>
    <div class="mb-3"><label class="form-label"><?= h(__('admin.groups.field_name')) ?></label><?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'required' => true]) ?></div>
    <div class="mb-3"><label class="form-label"><?= h(__('admin.groups.field_description')) ?></label><?= $this->Form->control('description', ['label' => false, 'type' => 'textarea', 'class' => 'form-control', 'rows' => 2]) ?></div>
    <?= $this->Form->button(__('admin.groups.add_submit'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div></div>
