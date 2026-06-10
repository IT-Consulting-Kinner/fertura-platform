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
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.tenants.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tenants.hint')) ?></p>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_name'), 'name', $query, ['action' => 'index']) ?>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_key'), 'key', $query, ['action' => 'index']) ?>
        <?= $this->UiKit->sortHeader(__('admin.tenants.col_active'), 'active', $query, ['action' => 'index']) ?>
    </tr></thead>
    <tbody>
    <?php foreach ($tenants as $t): ?>
        <tr>
            <td><?= h($t['name']) ?></td>
            <td><code class="small"><?= h($t['key']) ?></code></td>
            <td><?= $this->UiKit->value($t['active'], 'bool') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tenants === []): ?>
        <tr><td colspan="3" class="text-muted"><?= h(__('uikit.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?= $this->UiKit->paginate($page, $perPage, $total, ['action' => 'index'], $query) ?>

<hr class="my-4">
<h2 class="h5 mb-3"><?= h(__('admin.tenants.add_title')) ?></h2>
<?= $this->Form->create(null, ['url' => ['action' => 'add'], 'class' => 'col-md-6']) ?>
<?= $this->UiKit->fields([
    ['key' => 'key', 'label' => __('admin.tenants.col_key'), 'required' => true, 'help' => __('admin.tenants.key_help')],
    ['key' => 'name', 'label' => __('admin.tenants.col_name'), 'required' => true],
]) ?>
<?= $this->Form->button(__('admin.tenants.btn_add'), ['class' => 'btn btn-primary']) ?>
<?= $this->Form->end() ?>
