<?php
/**
 * @var \App\View\AppView $this
 * @var string $baseUrl
 * @var array<string, mixed>|null $metadata
 * @var string|null $error
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.marketplace.title')) ?></h1>
    <?= $this->Html->link(__('admin.marketplace.manage_licenses'), ['action' => 'licenses'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>

<div class="card mb-4"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-3"><?= h(__('admin.marketplace.url_label')) ?></dt>
        <dd class="col-sm-9"><?= $baseUrl !== '' ? h($baseUrl) : '<span class="text-muted">' . h(__('admin.marketplace.url_not_configured')) . '</span>' ?></dd>
    </dl>
    <?php if ($baseUrl !== ''): ?>
        <hr>
        <?= $this->Form->postLink(__('admin.marketplace.sync_now'), ['action' => 'sync'],
            ['class' => 'btn btn-primary btn-sm']) ?>
        <span class="text-muted small ms-2"><?= h(__('admin.marketplace.sync_hint')) ?></span>
    <?php endif; ?>
</div></div>

<?php if ($error !== null): ?>
    <div class="alert alert-warning"><?= h(__('admin.marketplace.metadata_error', $error)) ?></div>
<?php elseif ($metadata !== null): ?>
    <div class="card"><div class="card-header"><?= h(__('admin.marketplace.verified_metadata')) ?></div><div class="card-body">
        <pre class="mb-0 small"><?= h(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div></div>
<?php endif; ?>
