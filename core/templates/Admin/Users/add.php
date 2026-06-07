<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.users.add_title')) ?></h1>
    <?= $this->Html->link('&laquo; ' . __('admin.users.backtolist'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>
<div class="card"><div class="card-body" style="max-width:520px">
    <?= $this->Form->create($user) ?>
    <div class="mb-3"><?= $this->Form->control('username', ['class' => 'form-control', 'label' => __('admin.users.field_username')]) ?></div>
    <div class="mb-3"><?= $this->Form->control('email', ['class' => 'form-control', 'label' => __('admin.users.field_email')]) ?></div>
    <div class="row">
        <div class="col mb-3"><?= $this->Form->control('first_name', ['class' => 'form-control', 'label' => __('admin.users.field_first_name')]) ?></div>
        <div class="col mb-3"><?= $this->Form->control('last_name', ['class' => 'form-control', 'label' => __('admin.users.field_last_name')]) ?></div>
    </div>
    <p class="text-muted small"><?= __('admin.users.add_hint') ?></p>
    <?= $this->Form->button(__('admin.users.add_submit'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div></div>
