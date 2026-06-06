<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $interfaces
 * @var array<int, array<string, mixed>> $usages
 */
$b = static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
$spec = static function ($v): string {
    if ($v === null || $v === '') {
        return '–';
    }
    $d = is_string($v) ? json_decode($v, true) : $v;

    return $d === null ? (string)$v : (string)json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.interfaces.title')) ?></h1>
    <?= $this->Html->link(__('admin.interfaces.all_contracts'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>
<p class="text-muted small"><?= h(__('admin.interfaces.intro')) ?></p>

<h2 class="h5"><?= h(__('admin.interfaces.offered_heading')) ?></h2>
<?php foreach ($interfaces as $i): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><code><?= h($i['name']) ?></code> <span class="badge text-bg-secondary">v<?= h($i['version']) ?></span></span>
            <span>
                <?php if ($b($i['active']) && $i['provider'] !== null): ?>
                    <span class="badge text-bg-success"><?= h(__('admin.interfaces.available')) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-warning"><?= h(__('admin.interfaces.unavailable')) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body">
            <p class="mb-2"><?= h($i['description']) ?: '<span class="text-muted">' . h(__('admin.interfaces.no_description')) . '</span>' ?></p>
            <dl class="row mb-0 small">
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_provider_module')) ?></dt><dd class="col-sm-9"><?= h($i['owner_module_key']) ?></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_provider_active')) ?></dt><dd class="col-sm-9"><?= $i['provider'] !== null ? h($i['provider']) : '<span class="text-warning">' . h(__('admin.interfaces.provider_none')) . '</span>' ?></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_multi_use')) ?></dt><dd class="col-sm-9"><?= $b($i['multi_use']) ? h(__('admin.interfaces.multi_allowed')) : '<strong>' . h(__('admin.interfaces.multi_exclusive')) . '</strong>' ?></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_active_consumers')) ?></dt><dd class="col-sm-9"><?= (int)$i['active_consumers'] ?></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_input_spec')) ?></dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['input_spec'])) ?></code></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_output_spec')) ?></dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['output_spec'])) ?></code></dd>
                <dt class="col-sm-3"><?= h(__('admin.interfaces.dt_default_behavior')) ?></dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['default_behavior'])) ?></code></dd>
            </dl>
        </div>
    </div>
<?php endforeach; ?>
<?php if ($interfaces === []): ?><p class="text-muted"><?= h(__('admin.interfaces.offered_empty')) ?></p><?php endif; ?>

<h2 class="h5 mt-4"><?= h(__('admin.interfaces.usage_heading')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th><?= h(__('admin.interfaces.col_using_module')) ?></th><th><?= h(__('admin.interfaces.col_module_version')) ?></th><th><?= h(__('admin.interfaces.col_target_interface')) ?></th><th><?= h(__('admin.interfaces.col_interface_version')) ?></th><th><?= h(__('admin.interfaces.col_required')) ?></th><th><?= h(__('admin.interfaces.col_status')) ?></th><th><?= h(__('admin.interfaces.col_compatibility')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($usages as $u): $active = $b($u['active']) && $u['binding_status'] === 'active'; ?>
        <tr>
            <td><?= h($u['module_key']) ?></td>
            <td><?= h($u['module_version']) ?: '–' ?></td>
            <td><code class="small"><?= h($u['interface']) ?></code></td>
            <td><?= h($u['interface_version']) ?></td>
            <td><?= h($u['required_version']) ?: '–' ?></td>
            <td><span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? h(__('admin.interfaces.status_active')) : h(__('admin.interfaces.status_inactive')) ?></span></td>
            <td><?= $b($u['active']) ? '<span class="badge text-bg-success">' . h(__('admin.interfaces.compatible')) . '</span>' : '<span class="badge text-bg-secondary">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($usages === []): ?><tr><td colspan="7" class="text-muted"><?= h(__('admin.interfaces.usage_empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
