<?php
/**
 * @var \App\View\AppView $this
 * @var string $moduleKey
 * @var list<array<string,mixed>> $fields
 */
?>
<h1 class="h3 mb-1"><?= h(__('admin.tenant_modules.configure_title')) ?></h1>
<p class="text-muted small"><code><?= h($moduleKey) ?></code> — <?= h(__('admin.tenant_modules.configure_hint')) ?></p>

<?= $this->Form->create(null, ['url' => ['action' => 'configure', $moduleKey], 'class' => 'mw-md']) ?>
<?= $this->UiKit->fields($fields) ?>
<?= $this->Form->button(__('admin.tenant_modules.configure_save'), ['class' => 'btn btn-primary']) ?>
<?= $this->Html->link(__('admin.tenant_modules.configure_cancel'), ['action' => 'index'], ['class' => 'btn btn-link']) ?>
<?= $this->Form->end() ?>
