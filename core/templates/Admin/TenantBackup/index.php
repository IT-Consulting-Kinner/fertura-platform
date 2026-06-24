<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $backups
 * @var list<string> $scopes
 */
// Human label for a backup scope: full/core are translated, a module key shows as-is.
$scopeLabel = static function (string $s): string {
    if ($s === 'full') {
        return (string)__('admin.tenant_backup.scope_full');
    }
    if ($s === 'core') {
        return (string)__('admin.tenant_backup.scope_core');
    }

    return ucfirst($s);
};
$scopeOptions = [];
foreach ($scopes as $s) {
    $scopeOptions[$s] = $scopeLabel($s);
}
?>
<h1 class="h3 mb-1"><?= h(__('admin.tenant_backup.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tenant_backup.hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'create'], 'class' => 'row g-2 align-items-end col-md-9 mb-4']) ?>
    <div class="col-auto">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.scope')) ?></label>
        <?= $this->Form->control('scope', [
            'type' => 'select',
            'options' => $scopeOptions,
            'default' => 'full',
            'label' => false,
            'class' => 'form-select form-select-sm',
        ]) ?>
    </div>
    <div class="col">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.note')) ?></label>
        <?= $this->Form->control('note', ['label' => false, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto"><?= $this->Form->button(__('admin.tenant_backup.create'), ['class' => 'btn btn-primary btn-sm']) ?></div>
<?= $this->Form->end() ?>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.tenant_backup.col_created')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_scope')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_source')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_size')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_encrypted')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_note')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.tenant_backup.col_action')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td class="small"><?= $this->UiKit->value($b['created_at'], 'datetime') ?></td>
            <td class="small"><span class="badge text-bg-light"><?= h($scopeLabel((string)($b['scope'] ?? 'full'))) ?></span></td>
            <td class="small text-muted"><?= h((string)(($b['plan_id'] ?? null) !== null ? __('admin.tenant_backup.source_plan') : __('admin.tenant_backup.source_adhoc'))) ?></td>
            <td class="small"><?= h((string)(int)round(((int)$b['bytes']) / 1024)) ?> KB</td>
            <td><?= $this->UiKit->value($b['encrypted'], 'bool') ?></td>
            <td class="small text-muted"><?= h((string)($b['note'] ?? '')) ?></td>
            <td class="text-end">
                <?= $this->UiKit->confirmPost(
                    __('admin.tenant_backup.restore'),
                    ['action' => 'restore', $b['id']],
                    __('admin.tenant_backup.restore_confirm'),
                    ['class' => 'btn btn-sm btn-outline-danger', 'variant' => 'btn-danger'],
                ) ?>
                <?= $this->Form->create(null, ['url' => ['action' => 'download', $b['id']], 'class' => 'd-inline']) ?>
                <?= $this->Form->button(
                    __('admin.tenant_backup.download'),
                    ['class' => 'btn btn-sm btn-outline-secondary', 'type' => 'submit'],
                ) ?>
                <?= $this->Form->end() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($backups === []): ?>
        <tr><td colspan="7" class="text-muted"><?= h(__('admin.tenant_backup.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<hr class="my-4">

<?php
/** @var list<array<string,mixed>> $plans */
$dow = [];
foreach (range(0, 6) as $d) {
    $dow[$d] = (string)__('admin.tenant_backup.dow_' . $d);
}
// Human schedule description per plan.
$schedule = static function (array $p) use ($dow): string {
    $h = str_pad((string)(int)$p['hour'], 2, '0', STR_PAD_LEFT) . ':00';
    return match ((string)$p['cadence']) {
        'weekly' => (string)__('admin.tenant_backup.sched_weekly', $dow[(int)($p['weekday'] ?? 1)] ?? '', $h),
        'monthly' => (string)__('admin.tenant_backup.sched_monthly', (string)(int)($p['day_of_month'] ?? 1), $h),
        default => (string)__('admin.tenant_backup.sched_daily', $h),
    };
};
?>
<h2 class="h5 mb-1"><?= h(__('admin.tenant_backup.plans_title')) ?></h2>
<p class="text-muted small"><?= h(__('admin.tenant_backup.plans_hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'createPlan'], 'class' => 'row g-2 align-items-end col-lg-12 mb-3']) ?>
    <div class="col-auto" style="min-width:9rem">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_name')) ?></label>
        <?= $this->Form->control('name', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.scope')) ?></label>
        <?= $this->Form->control('scope', ['type' => 'select', 'options' => $scopeOptions, 'default' => 'full', 'label' => false, 'class' => 'form-select form-select-sm']) ?>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_cadence')) ?></label>
        <?= $this->Form->control('cadence', ['type' => 'select', 'options' => [
            'daily' => __('admin.tenant_backup.cadence_daily'),
            'weekly' => __('admin.tenant_backup.cadence_weekly'),
            'monthly' => __('admin.tenant_backup.cadence_monthly'),
        ], 'label' => false, 'class' => 'form-select form-select-sm']) ?>
    </div>
    <div class="col-auto" style="width:6rem">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_hour')) ?></label>
        <?= $this->Form->control('hour', ['type' => 'number', 'min' => 0, 'max' => 23, 'default' => 2, 'label' => false, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_weekday')) ?></label>
        <?= $this->Form->control('weekday', ['type' => 'select', 'options' => $dow, 'default' => 1, 'label' => false, 'class' => 'form-select form-select-sm']) ?>
    </div>
    <div class="col-auto" style="width:6rem">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_day')) ?></label>
        <?= $this->Form->control('day_of_month', ['type' => 'number', 'min' => 1, 'max' => 28, 'default' => 1, 'label' => false, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto" style="width:6rem">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.plan_retention')) ?></label>
        <?= $this->Form->control('retention_keep', ['type' => 'number', 'min' => 1, 'max' => 365, 'default' => 7, 'label' => false, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col-auto">
        <div class="form-check">
            <?= $this->Form->checkbox('active', ['checked' => true, 'class' => 'form-check-input', 'id' => 'planActive']) ?>
            <label class="form-check-label small" for="planActive"><?= h(__('admin.tenant_backup.plan_active')) ?></label>
        </div>
    </div>
    <div class="col-auto"><?= $this->Form->button(__('admin.tenant_backup.plan_add'), ['class' => 'btn btn-primary btn-sm']) ?></div>
<?= $this->Form->end() ?>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.tenant_backup.plan_name')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_scope')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.col_plan_schedule')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.plan_retention')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.plan_next_run')) ?></th>
        <th scope="col"><?= h(__('admin.tenant_backup.plan_active')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.tenant_backup.col_action')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($plans as $p): ?>
        <tr>
            <td class="small"><?= h((string)$p['name']) ?></td>
            <td class="small"><span class="badge text-bg-light"><?= h($scopeLabel((string)$p['scope'])) ?></span></td>
            <td class="small text-muted"><?= h($schedule($p)) ?></td>
            <td class="small"><?= h((string)(int)$p['retention_keep']) ?></td>
            <td class="small text-muted"><?= $this->UiKit->value($p['next_run_at'], 'datetime') ?></td>
            <td><?= $this->UiKit->value($p['active'], 'bool') ?></td>
            <td class="text-end">
                <?= $this->Form->create(null, ['url' => ['action' => 'togglePlan', $p['id']], 'class' => 'd-inline']) ?>
                <?= $this->Form->hidden('active', ['value' => (bool)$p['active'] ? '0' : '1']) ?>
                <?= $this->Form->button(
                    (bool)$p['active'] ? __('admin.tenant_backup.plan_deactivate') : __('admin.tenant_backup.plan_activate'),
                    ['class' => 'btn btn-sm btn-outline-secondary', 'type' => 'submit'],
                ) ?>
                <?= $this->Form->end() ?>
                <?= $this->UiKit->confirmPost(
                    __('admin.tenant_backup.plan_delete'),
                    ['action' => 'deletePlan', $p['id']],
                    __('admin.tenant_backup.plan_delete_confirm'),
                    ['class' => 'btn btn-sm btn-outline-danger', 'variant' => 'btn-danger'],
                ) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($plans === []): ?>
        <tr><td colspan="7" class="text-muted"><?= h(__('admin.tenant_backup.plan_none')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<hr class="my-4">

<h2 class="h5 mb-1"><?= h(__('admin.tenant_backup.upload_title')) ?></h2>
<p class="text-muted small"><?= h(__('admin.tenant_backup.upload_hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'upload'], 'type' => 'file', 'class' => 'row g-2 align-items-end col-md-9']) ?>
    <div class="col">
        <label class="form-label small mb-0"><?= h(__('admin.tenant_backup.upload_file')) ?></label>
        <?= $this->Form->control('archive', [
            'type' => 'file',
            'label' => false,
            'accept' => '.zip',
            'class' => 'form-control form-control-sm',
        ]) ?>
    </div>
    <div class="col-auto">
        <?= $this->Form->button(__('admin.tenant_backup.upload'), [
            'class' => 'btn btn-sm btn-outline-danger',
            'type' => 'submit',
            'data-confirm' => __('admin.tenant_backup.upload_confirm'),
            'data-confirm-variant' => 'btn-danger',
        ]) ?>
    </div>
<?= $this->Form->end() ?>
