<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var array<int, array<string, mixed>> $groups          Current memberships (each removable).
 * @var array<string, string> $availableGroups            Tenant's OTHER active groups (addable).
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.users.edit_title')) ?></h1>
    <?= $this->Html->link('&laquo; ' . __('admin.users.back'), ['action' => 'view', $user->id], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>
<div class="row g-4">
    <div class="col-md-6">
        <div class="card"><div class="card-body">
            <?= $this->Form->create($user) ?>
            <div class="mb-3"><?= $this->Form->control('username', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_username')]) ?></div>
            <div class="mb-3"><?= $this->Form->control('email', ['type' => 'email', 'class' => 'form-control', 'label' => __('admin.users.field_email')]) ?></div>
            <div class="row">
                <div class="col mb-3"><?= $this->Form->control('first_name', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_first_name')]) ?></div>
                <div class="col mb-3"><?= $this->Form->control('last_name', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_last_name')]) ?></div>
            </div>
            <?= $this->Form->button(__('admin.users.edit_submit'), ['class' => 'btn btn-primary']) ?>
            <?= $this->Form->end() ?>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><?= h(__('admin.users.groups')) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($groups as $g): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($g['name']) ?></span>
                        <?php // Mandatory group (no group-less users): the last one has no remove button. ?>
                        <?php if (count($groups) > 1): ?>
                            <?= $this->Form->postLink(__('admin.users.group_remove'), ['action' => 'removeGroup', $user->id, $g['id']], ['class' => 'btn btn-outline-danger btn-sm']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($groups === []): ?>
                    <li class="list-group-item text-muted"><?= h(__('admin.users.groups_empty')) ?></li>
                <?php endif; ?>
            </ul>
            <div class="card-body">
                <?php if ($availableGroups !== []): ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'addGroup', $user->id], 'class' => 'input-group input-group-sm']) ?>
                        <?= $this->Form->control('group_id', ['type' => 'select', 'options' => $availableGroups, 'empty' => __('admin.users.group_add_choose'), 'label' => false, 'class' => 'form-select', 'required' => true]) ?>
                        <?= $this->Form->button(__('admin.users.group_add'), ['class' => 'btn btn-outline-primary']) ?>
                    <?= $this->Form->end() ?>
                <?php else: ?>
                    <p class="mb-0 small text-muted"><?= h(__('admin.users.groups_all_assigned')) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
