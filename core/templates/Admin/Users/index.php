<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $users
 * @var \App\Model\Entity\User $user      Empty entity (or, on a failed create, the one with errors).
 * @var bool $openCreate                  Expand the create accordion (after a failed create).
 * @var array<string, string> $groupOptions Active groups of the tenant (id => name); a group is mandatory.
 * @var string $q          Active search filter (username/email/name).
 * @var string $status     Active status filter ('' = all).
 * @var int $perPage
 * @var int $page
 * @var int $total
 * @var array<string, mixed> $query   Filter/per-page bag for the pagination links.
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
                <div class="mb-3"><?= $this->Form->control('username', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_username')]) ?></div>
                <div class="mb-3"><?= $this->Form->control('email', ['type' => 'email', 'class' => 'form-control', 'label' => __('admin.users.field_email')]) ?></div>
                <div class="row">
                    <div class="col mb-3"><?= $this->Form->control('first_name', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_first_name')]) ?></div>
                    <div class="col mb-3"><?= $this->Form->control('last_name', ['type' => 'text', 'class' => 'form-control', 'label' => __('admin.users.field_last_name')]) ?></div>
                </div>
                <?php // A user may be created in SEVERAL groups at once (multi-select
                      // list). The reload button re-fetches the options (UiKit
                      // options-refresh, multi-select-aware in ui.js) so a group created
                      // in the "new group" tab appears without losing the current pick. ?>
                <div class="mb-3">
                    <label class="form-label d-block" for="user-group-ids"><?= h(__('admin.users.field_groups')) ?></label>
                    <?= $this->Form->select('group_ids', $groupOptions, [
                        'multiple' => true,
                        'id' => 'user-group-ids',
                        'class' => 'form-select',
                        'size' => 5,
                    ]) ?>
                    <div class="form-text d-flex align-items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-options-refresh="/admin/groups/options" data-options-target="#user-group-ids"><?= h(__('admin.users.groups_reload')) ?></button>
                        <?= $this->Html->link(__('admin.users.group_create_new'), '/admin/groups?create=1', ['target' => '_blank', 'rel' => 'noopener']) ?>
                        <span class="text-muted"><?= h(__('admin.users.groups_multi_hint')) ?></span>
                    </div>
                </div>
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

<?php // Filter + page size (per-user list preferences, Paket 2). The GET submit
      // carries the `_lp` marker so the controller persists this state. ?>
<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end mb-3']) ?>
    <?= $this->Form->hidden('_lp', ['value' => 1]) ?>
    <div class="col-auto">
        <label class="form-label small mb-0" for="users-q"><?= h(__('admin.users.filter_search')) ?></label>
        <?= $this->Form->text('q', ['id' => 'users-q', 'value' => $q, 'class' => 'form-control form-control-sm', 'placeholder' => __('admin.users.filter_search_ph')]) ?>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0" for="users-status"><?= h(__('admin.users.col_status')) ?></label>
        <?= $this->Form->select('status', ['active' => 'active', 'invited' => 'invited', 'disabled' => 'disabled', 'anonymized' => 'anonymized'],
            ['id' => 'users-status', 'value' => $status, 'empty' => __('admin.users.filter_status_all'), 'class' => 'form-select form-select-sm']) ?>
    </div>
    <?= $this->UiKit->perPageSelect($perPage) ?>
    <div class="col-auto">
        <?= $this->Form->button(__('admin.users.filter_apply'), ['class' => 'btn btn-primary btn-sm']) ?>
        <?= $this->Html->link(__('admin.users.filter_reset'), ['action' => 'index', '?' => ['_lp' => 1]], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>
<?= $this->Form->end() ?>

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
<?= $this->UiKit->paginate($page, $perPage, $total, ['action' => 'index'], $query) ?>
