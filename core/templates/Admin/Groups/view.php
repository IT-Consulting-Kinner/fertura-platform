<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $group
 * @var array<int, array<string, mixed>> $members
 * @var array<int, array<string, mixed>> $candidates
 * @var array<int, array<string, mixed>> $permissions
 * @var array<int, array<string, mixed>> $resources
 */
$active = filter_var($group['active'], FILTER_VALIDATE_BOOLEAN);
$flag = static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? '✓' : '–';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h($group['name']) ?>
        <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?> align-middle"><?= h($active ? __('admin.groups.active') : __('admin.groups.inactive')) ?></span></h1>
    <div class="d-flex gap-2">
        <?= $this->Form->postLink($active ? __('admin.groups.deactivate') : __('admin.groups.activate'),
            ['action' => 'setActive', $group['id'], $active ? 'off' : 'on'],
            ['class' => 'btn btn-sm ' . ($active ? 'btn-warning' : 'btn-success')]) ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><?= h(__('admin.groups.members')) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($members as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($m['username']) ?> <span class="text-muted small"><?= h($m['email']) ?></span></span>
                        <?= $this->UiKit->confirmPost(__('admin.groups.member_remove'), ['action' => 'removeMember', $group['id'], $m['id']], __('admin.groups.member_remove_confirm'), ['class' => 'btn btn-outline-danger btn-sm']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($members === []): ?><li class="list-group-item text-muted"><?= h(__('admin.groups.members_empty')) ?></li><?php endif; ?>
            </ul>
            <div class="card-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'addMember', $group['id']]]) ?>
                <div class="input-group input-group-sm">
                    <?= $this->Form->select('user_id', array_column($candidates, 'username', 'id'),
                        ['empty' => __('admin.groups.member_select'), 'class' => 'form-select']) ?>
                    <?= $this->Form->button(__('admin.groups.member_add'), ['class' => 'btn btn-primary']) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card mb-4">
            <div class="card-header"><?= h(__('admin.groups.perms_granted')) ?></div>
            <table class="table table-sm mb-0">
                <thead><tr><th scope="col"><?= h(__('admin.groups.perms_col_resource')) ?></th><th scope="col"><?= h(__('admin.groups.perms_col_object')) ?></th><th scope="col" class="text-center"><abbr title="<?= h(__('admin.groups.perm_browse')) ?>">B</abbr></th><th scope="col" class="text-center"><abbr title="<?= h(__('admin.groups.perm_read')) ?>">R</abbr></th><th scope="col" class="text-center"><abbr title="<?= h(__('admin.groups.perm_add')) ?>">A</abbr></th><th scope="col" class="text-center"><abbr title="<?= h(__('admin.groups.perm_edit')) ?>">E</abbr></th><th scope="col" class="text-center"><abbr title="<?= h(__('admin.groups.perm_delete')) ?>">D</abbr></th><th scope="col"><?= h(__('admin.groups.perms_col_extra')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($permissions as $p): ?>
                    <?php $ex = is_string($p['extra_actions'] ?? null) ? (json_decode((string)$p['extra_actions'], true) ?: []) : (array)($p['extra_actions'] ?? []); ?>
                    <tr>
                        <td><code class="small"><?= h($p['module_key']) ?>::<?= h($p['resource_type']) ?></code></td>
                        <td class="small"><?= $p['resource_key'] === null ? '<span class="text-muted">' . h(__('admin.groups.perms_class')) . '</span>' : h((string)$p['resource_key']) ?></td>
                        <td class="text-center"><?= $flag($p['can_browse']) ?></td>
                        <td class="text-center"><?= $flag($p['can_read']) ?></td>
                        <td class="text-center"><?= $flag($p['can_add']) ?></td>
                        <td class="text-center"><?= $flag($p['can_edit']) ?></td>
                        <td class="text-center"><?= $flag($p['can_delete']) ?></td>
                        <td class="small"><?= h(implode(', ', array_keys(array_filter($ex)))) ?: '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($permissions === []): ?><tr><td colspan="8" class="text-muted"><?= h(__('admin.groups.perms_empty')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="card-header"><?= h(__('admin.groups.perms_editor_title')) ?></div>
            <div class="card-body">
                <?php if ($resources === []): ?>
                    <p class="text-muted mb-0"><?= h(__('admin.groups.perms_editor_none')) ?></p>
                <?php else: ?>
                    <p class="text-muted small"><?= h(__('admin.groups.perms_editor_hint')) ?></p>
                    <?php foreach ($resources as $r):
                        $rid = $r['module_key'] . '::' . $r['resource_type'];
                        $rkey = str_replace(['.', ':'], '_', $rid);
                        $extra = is_string($r['extra_actions'] ?? null) ? (json_decode((string)$r['extra_actions'], true) ?: []) : (array)($r['extra_actions'] ?? []);
                    ?>
                        <div class="border rounded p-2 mb-2">
                            <?= $this->Form->create(null, ['url' => ['action' => 'setPermission', $group['id']], 'class' => 'd-flex flex-wrap gap-2 align-items-end']) ?>
                            <?= $this->Form->hidden('resource', ['value' => $rid]) ?>
                            <div class="w-100"><strong class="small"><?= h($r['resource_name']) ?></strong>
                                <code class="small text-muted"><?= h($rid) ?></code>
                                <?php if (filter_var($r['is_scoped'], FILTER_VALIDATE_BOOLEAN)): ?><span class="badge text-bg-light">scoped</span><?php endif; ?>
                            </div>
                            <?php foreach (['browse' => 'B', 'read' => 'R', 'add' => 'A', 'edit' => 'E', 'delete' => 'D'] as $a => $lbl): ?>
                                <div class="form-check"><?= $this->Form->checkbox('can_' . $a, ['class' => 'form-check-input', 'id' => $rkey . '_' . $a, 'aria-label' => __('admin.groups.perm_' . $a)]) ?>
                                    <label class="form-check-label small" for="<?= $rkey . '_' . $a ?>" title="<?= h(__('admin.groups.perm_' . $a)) ?>"><?= $lbl ?></label></div>
                            <?php endforeach; ?>
                            <?php foreach (array_values($extra) as $ea): $ea = (string)$ea; if ($ea === '') { continue; } ?>
                                <div class="form-check"><?= $this->Form->checkbox('extra[' . $ea . ']', ['class' => 'form-check-input', 'id' => $rkey . '_x_' . $ea]) ?>
                                    <label class="form-check-label small" for="<?= $rkey . '_x_' . $ea ?>"><?= h($ea) ?></label></div>
                            <?php endforeach; ?>
                            <?= $this->Form->control('resource_key', ['label' => false, 'placeholder' => __('admin.groups.perms_object_placeholder'), 'class' => 'form-control form-control-sm', 'style' => 'max-width:150px']) ?>
                            <?= $this->Form->button(__('admin.groups.perms_save'), ['class' => 'btn btn-outline-primary btn-sm']) ?>
                            <?= $this->Form->end() ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
