<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $group
 * @var array<int, array<string, mixed>> $members
 * @var array<int, array<string, mixed>> $candidates
 * @var array<string, array<string, mixed>> $grants          Class-level grant per "module::type".
 * @var array<string, array{name: string, resources: array<int, array<string, mixed>>}> $resourceGroups
 */
$active = filter_var($group['active'], FILTER_VALIDATE_BOOLEAN);
$truthy = static fn ($v) => filter_var($v ?? false, FILTER_VALIDATE_BOOLEAN);
// Module-domain label with fallback: __d() returns the key untranslated, then
// the technical name from the registry is shown instead (hand-off: modules ship
// perm.resource.<type> / perm.action.<name> in their language packs).
$dLabel = static function (string $domain, string $key, string $fallback): string {
    $t = __d($domain, $key);

    return $t === $key ? $fallback : $t;
};
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
        <div class="card">
            <div class="card-header"><?= h(__('admin.groups.perms_editor_title')) ?></div>
            <div class="card-body">
                <?php if ($resourceGroups === []): ?>
                    <p class="text-muted mb-0"><?= h(__('admin.groups.perms_editor_none')) ?></p>
                <?php else: ?>
                    <p class="text-muted small"><?= h(__('admin.groups.perms_editor_hint')) ?></p>
                    <p class="text-muted small"><?= h(__('admin.groups.perms_scoped_hint')) ?></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'savePermissions', $group['id']]]) ?>
                    <?php foreach ($resourceGroups as $moduleKey => $module): ?>
                        <?php ob_start(); ?>
                        <?php foreach ($module['resources'] as $r):
                            $rid = $r['module_key'] . '::' . $r['resource_type'];
                            $rkey = str_replace(['.', ':'], '_', $rid);
                            $grant = $grants[$rid] ?? null;
                            $grantExtra = $grant !== null && is_string($grant['extra_actions'] ?? null)
                                ? (json_decode((string)$grant['extra_actions'], true) ?: [])
                                : (array)($grant['extra_actions'] ?? []);
                            $declared = is_string($r['extra_actions'] ?? null)
                                ? (json_decode((string)$r['extra_actions'], true) ?: [])
                                : (array)($r['extra_actions'] ?? []);
                            $resourceLabel = $dLabel((string)$r['module_key'], 'perm.resource.' . $r['resource_type'], (string)$r['resource_name']);
                        ?>
                            <div class="border rounded p-2 mb-2 d-flex flex-wrap gap-3 align-items-center">
                                <div class="w-100"><strong class="small"><?= h($resourceLabel) ?></strong>
                                    <code class="small text-muted"><?= h($rid) ?></code>
                                    <?php if ($truthy($r['is_scoped'])): ?><span class="badge text-bg-light" title="<?= h(__('admin.groups.scoped_badge_title')) ?>">scoped</span><?php endif; ?>
                                </div>
                                <?php // BREAD order: Browse, Read, Edit, Add, Delete. ?>
                                <?php foreach (['browse' => 'B', 'read' => 'R', 'edit' => 'E', 'add' => 'A', 'delete' => 'D'] as $a => $lbl): ?>
                                    <div class="form-check"><?= $this->Form->checkbox('perm.' . $rid . '.' . $a, ['class' => 'form-check-input', 'id' => $rkey . '_' . $a, 'checked' => $grant !== null && $truthy($grant['can_' . $a]), 'hiddenField' => false]) ?>
                                        <label class="form-check-label small" for="<?= $rkey . '_' . $a ?>" title="<?= h(__('admin.groups.perm_' . $a)) ?>"><?= $lbl ?></label></div>
                                <?php endforeach; ?>
                                <?php foreach (array_values($declared) as $ea): $ea = (string)$ea; if ($ea === '') { continue; } ?>
                                    <div class="form-check"><?= $this->Form->checkbox('perm.' . $rid . '.x.' . $ea, ['class' => 'form-check-input', 'id' => $rkey . '_x_' . $ea, 'checked' => (bool)($grantExtra[$ea] ?? false), 'hiddenField' => false]) ?>
                                        <label class="form-check-label small" for="<?= $rkey . '_x_' . $ea ?>"><?= h($dLabel((string)$r['module_key'], 'perm.action.' . $ea, $ea)) ?></label></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <?= $this->UiKit->formAccordion($module['name'], (string)ob_get_clean(), ['open' => true, 'id' => 'perm-' . str_replace('.', '-', (string)$moduleKey)]) ?>
                    <?php endforeach; ?>
                    <?= $this->Form->button(__('admin.groups.perms_save'), ['class' => 'btn btn-primary']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
