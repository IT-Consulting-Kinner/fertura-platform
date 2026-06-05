<?php
/**
 * @var \App\View\AppView $this
 * @var string $baseUrl
 * @var array<string, mixed>|null $metadata
 * @var string|null $error
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Marketplace</h1>
    <?= $this->Html->link('Lizenzen verwalten', ['action' => 'licenses'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>

<div class="card mb-4"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-3">Marketplace-URL</dt>
        <dd class="col-sm-9"><?= $baseUrl !== '' ? h($baseUrl) : '<span class="text-muted">nicht konfiguriert (core.marketplace.base_url)</span>' ?></dd>
    </dl>
    <?php if ($baseUrl !== ''): ?>
        <hr>
        <?= $this->Form->postLink('Jetzt synchronisieren', ['action' => 'sync'],
            ['class' => 'btn btn-primary btn-sm']) ?>
        <span class="text-muted small ms-2">Holt Trust-Anchors und Sperrliste (CRL).</span>
    <?php endif; ?>
</div></div>

<?php if ($error !== null): ?>
    <div class="alert alert-warning">Metadaten konnten nicht geladen werden: <?= h($error) ?></div>
<?php elseif ($metadata !== null): ?>
    <div class="card"><div class="card-header">Verifizierte Metadaten</div><div class="card-body">
        <pre class="mb-0 small"><?= h(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div></div>
<?php endif; ?>
