<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{module_key:string, name:string, version:string, enabled:bool}> $modules
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.tenant_modules.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tenant_modules.hint')) ?></p>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.tenant_modules.col_module')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_modules.col_version')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_modules.col_status')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.tenant_modules.col_action')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($modules as $m): ?>
        <tr>
            <td><?= h($m['name']) ?> <code class="small text-muted"><?= h($m['module_key']) ?></code></td>
            <td class="small"><?= h($m['version']) ?></td>
            <td><?= $this->UiKit->value($m['enabled'], 'bool') ?></td>
            <td class="text-end">
                <?php if ($m['enabled']): ?>
                    <?= $this->UiKit->confirmPost(
                        __('admin.tenant_modules.disable'),
                        ['action' => 'disable', $m['module_key']],
                        __('admin.tenant_modules.disable_confirm', $m['name']),
                        ['class' => 'btn btn-sm btn-outline-warning', 'variant' => 'btn-warning'],
                    ) ?>
                <?php else: ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'enable', $m['module_key']], 'class' => 'd-inline']) ?>
                    <?= $this->Form->button(__('admin.tenant_modules.enable'), ['class' => 'btn btn-sm btn-outline-success', 'type' => 'submit']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($modules === []): ?>
        <tr><td colspan="4" class="text-muted"><?= h(__('admin.tenant_modules.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
