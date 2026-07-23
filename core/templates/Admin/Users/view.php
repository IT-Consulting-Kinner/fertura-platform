<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $areas
 * @var array<int, array<string, mixed>> $groups
 * @var bool $isSelf   Viewing one's own account -> self-management belongs in "My Profile".
 */
$badge = ['active' => 'success', 'invited' => 'info', 'disabled' => 'secondary', 'anonymized' => 'dark'];
$anon = $user['status'] === 'anonymized';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h($user['username']) ?></h1>
    <div class="d-flex gap-2">
        <?php // Self-edit is refused server-side (belongs in "My Profile") -> no Edit button for self. ?>
        <?php if ($user['status'] !== 'anonymized' && !$isSelf): ?>
            <?= $this->Html->link(__('admin.users.edit'), ['action' => 'edit', $user['id']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><?= h(__('admin.users.masterdata')) ?></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4"><?= h(__('admin.users.email')) ?></dt><dd class="col-sm-8"><?= h($user['email']) ?></dd>
                    <dt class="col-sm-4"><?= h(__('admin.users.name')) ?></dt><dd class="col-sm-8"><?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: '–' ?></dd>
                    <dt class="col-sm-4"><?= h(__('admin.users.status')) ?></dt><dd class="col-sm-8"><span class="badge text-bg-<?= $badge[$user['status']] ?? 'secondary' ?>"><?= h($user['status']) ?></span></dd>
                </dl>
                <?php if (!$anon && $isSelf): ?>
                <hr>
                <p class="mb-0 small text-muted"><?= h(__('admin.users.self_hint')) ?></p>
                <?php elseif (!$anon): ?>
                <hr>
                <div class="d-flex gap-2">
                    <?php if ($user['status'] !== 'active'): ?>
                        <?= $this->Form->postLink(__('admin.users.activate'), ['action' => 'setStatus', $user['id'], 'active'], ['class' => 'btn btn-success btn-sm']) ?>
                    <?php else: ?>
                        <?= $this->Form->postLink(__('admin.users.deactivate'), ['action' => 'setStatus', $user['id'], 'disabled'], ['class' => 'btn btn-warning btn-sm']) ?>
                    <?php endif; ?>
                    <?= $this->UiKit->confirmPost(__('admin.users.anonymize'), ['action' => 'anonymize', $user['id']], __('admin.users.anonymize_confirm'), ['class' => 'btn btn-outline-danger btn-sm']) ?>
                </div>
                <hr>
                <p class="mb-1 small text-muted"><?= h(__('admin.users.invite_password')) ?></p>
                <div class="d-flex gap-2 mb-2">
                    <?= $this->Form->postLink(__('admin.users.invite_create'), ['action' => 'invite', $user['id']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
                <?= $this->Form->create(null, ['url' => ['action' => 'setPassword', $user['id']], 'class' => 'input-group input-group-sm']) ?>
                    <?= $this->Form->control('password', ['type' => 'password', 'label' => false, 'class' => 'form-control', 'placeholder' => __('admin.users.password_placeholder'), 'required' => true]) ?>
                    <?= $this->Form->button(__('admin.users.password_set'), ['class' => 'btn btn-outline-secondary']) ?>
                <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header"><?= h(__('admin.users.groups')) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($groups as $g): ?>
                    <li class="list-group-item"><?= h($g['name']) ?></li>
                <?php endforeach; ?>
                <?php if ($groups === []): ?><li class="list-group-item text-muted"><?= h(__('admin.users.groups_empty')) ?></li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><?= h(__('admin.users.areas')) ?></div>
            <div class="card-body pb-2">
                <?php // Admin areas are granted via GROUPS now — this list is read-only. ?>
                <p class="text-muted small mb-0"><?= h(__('admin.users.areas_via_groups')) ?></p>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($areas as $a): $held = filter_var($a['held'], FILTER_VALIDATE_BOOLEAN); ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center<?= $held ? '' : ' text-muted' ?>">
                        <span><?= h($a['label']) ?> <code class="small text-muted"><?= h($a['area_key']) ?></code></span>
                        <?php if ($held): ?><span class="badge text-bg-success"><?= h(__('admin.users.area_active')) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
