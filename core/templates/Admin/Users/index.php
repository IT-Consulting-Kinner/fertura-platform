<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $users
 * @var \App\Model\Entity\User $user      Empty entity (or, on a failed create, the one with errors).
 * @var bool $openCreate                  Expand the create accordion (after a failed create).
 * @var array<string, string> $groupOptions Active groups of the tenant (id => name); a group is mandatory.
 */
$badge = ['active' => 'success', 'invited' => 'info', 'disabled' => 'secondary', 'anonymized' => 'dark'];
$open = !empty($openCreate);
?>
<h1 class="h3 mb-3"><?= h(__('admin.users.title')) ?></h1>

<div class="accordion mb-4" id="userCreate">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button<?= $open ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#createUser" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="createUser">
                <?= h(__('admin.users.new')) ?>
            </button>
        </h2>
        <div id="createUser" class="accordion-collapse collapse<?= $open ? ' show' : '' ?>">
            <div class="accordion-body" style="max-width:560px">
                <?= $this->Form->create($user, ['url' => ['action' => 'add']]) ?>
                <div class="mb-3"><?= $this->Form->control('username', ['class' => 'form-control', 'label' => __('admin.users.field_username')]) ?></div>
                <div class="mb-3"><?= $this->Form->control('email', ['class' => 'form-control', 'label' => __('admin.users.field_email')]) ?></div>
                <div class="row">
                    <div class="col mb-3"><?= $this->Form->control('first_name', ['class' => 'form-control', 'label' => __('admin.users.field_first_name')]) ?></div>
                    <div class="col mb-3"><?= $this->Form->control('last_name', ['class' => 'form-control', 'label' => __('admin.users.field_last_name')]) ?></div>
                </div>
                <?= $this->UiKit->fields([[
                    // Reference field: a "create a new group" link (opens the group
                    // admin with its create form expanded, new tab) + an options-
                    // refresh button, so a missing group can be created without
                    // abandoning this user form. The link is area-gated to
                    // user_group_admin (which the viewer holds on this page).
                    'key' => 'group_id',
                    'input' => 'select',
                    'label' => __('admin.users.field_group'),
                    'options' => $groupOptions,
                    'empty' => __('admin.users.field_group_choose'),
                    'required' => true,
                    'reference' => [
                        'url' => '/admin/groups?create=1',
                        'area' => 'user_group_admin',
                        'options_url' => '/admin/groups/options',
                    ],
                ]]) ?>
                <?php if ($groupOptions === []): ?>
                    <p class="text-warning small"><?= h(__('admin.users.no_groups_hint')) ?></p>
                <?php endif; ?>
                <p class="text-muted small"><?= __('admin.users.add_hint') ?></p>
                <?= $this->Form->button(__('admin.users.add_submit'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.users.col_username')) ?></th><th scope="col"><?= h(__('admin.users.col_name')) ?></th><th scope="col"><?= h(__('admin.users.col_email')) ?></th><th scope="col"><?= h(__('admin.users.col_groups')) ?></th><th scope="col"><?= h(__('admin.users.col_status')) ?></th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= h($u['username']) ?></td>
            <td><?= h(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
            <td><?= h($u['email']) ?></td>
            <td class="small"><?= $u['group_names'] !== null && $u['group_names'] !== ''
                ? h((string)$u['group_names'])
                : '<span class="text-warning">' . h(__('admin.users.no_group')) . '</span>' ?></td>
            <td><span class="badge text-bg-<?= $badge[$u['status']] ?? 'secondary' ?>"><?= h($u['status']) ?></span></td>
            <td class="text-end"><?= $this->Html->link(__('admin.users.details'), ['action' => 'view', $u['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?>
        <tr><td colspan="6" class="text-muted"><?= h(__('admin.users.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
