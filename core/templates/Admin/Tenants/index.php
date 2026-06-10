<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{id:string,key:string,name:string,active:bool}> $tenants
 * @var array<string,mixed> $query
 * @var string $sort
 * @var string $dir
 * @var int $page
 * @var int $perPage
 * @var int $total
 * @var array<string,string> $allTenants
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.tenants.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tenants.hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'bulk']]) ?>
<?= $this->UiKit->bulkActions([
    ['value' => 'activate', 'label' => __('admin.tenants.bulk_activate'), 'class' => 'btn btn-sm btn-outline-success'],
    ['value' => 'suspend', 'label' => __('admin.tenants.bulk_suspend'), 'class' => 'btn btn-sm btn-outline-warning',
        'confirm' => __('admin.tenants.bulk_suspend_confirm')],
]) ?>
<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th style="width:1%"><input type="checkbox" class="form-check-input"
            onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="Alle"></th>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_name'), 'name', $query, ['action' => 'index']) ?>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_key'), 'key', $query, ['action' => 'index']) ?>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_active'), 'active', $query, ['action' => 'index']) ?>
    </tr></thead>
    <tbody>
    <?php foreach ($tenants as $t): ?>
        <tr>
            <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= h($t['id']) ?>"></td>
            <td><?= h($t['name']) ?></td>
            <td><code class="small"><?= h($t['key']) ?></code></td>
            <td><?= $this->UiKit->value($t['active'], 'bool') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tenants === []): ?>
        <tr><td colspan="4" class="text-muted"><?= h(__('uikit.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?= $this->Form->end() ?>
<?= $this->UiKit->paginate($page, $perPage, $total, ['action' => 'index'], $query) ?>

<hr class="my-4">
<h2 class="h5 mb-3"><?= h(__('admin.tenants.assign_title')) ?></h2>
<?= $this->Form->create(null, ['url' => ['action' => 'assign'], 'class' => 'row g-2 align-items-end col-md-9']) ?>
    <div class="col">
        <label class="form-label small mb-0"><?= h(__('admin.tenants.assign_email')) ?></label>
        <?= $this->Form->control('email', ['label' => false, 'type' => 'email', 'required' => true, 'class' => 'form-control form-control-sm']) ?>
    </div>
    <div class="col">
        <label class="form-label small mb-0"><?= h(__('admin.tenants.assign_tenant')) ?></label>
        <?= $this->Form->select('tenant_id', $allTenants, ['class' => 'form-select form-select-sm', 'required' => true]) ?>
    </div>
    <div class="col-auto"><?= $this->Form->button(__('admin.tenants.assign_btn'), ['class' => 'btn btn-outline-primary btn-sm']) ?></div>
<?= $this->Form->end() ?>

<hr class="my-4">
<h2 class="h5 mb-3"><?= h(__('admin.tenants.add_title')) ?></h2>
<?= $this->Form->create(null, ['url' => ['action' => 'add'], 'class' => 'col-md-6']) ?>
<?= $this->UiKit->fields([
    ['key' => 'key', 'label' => __('admin.tenants.col_key'), 'required' => true, 'help' => __('admin.tenants.key_help')],
    ['key' => 'name', 'label' => __('admin.tenants.col_name'), 'required' => true],
]) ?>
<?= $this->Form->button(__('admin.tenants.btn_add'), ['class' => 'btn btn-primary']) ?>
<?= $this->Form->end() ?>
