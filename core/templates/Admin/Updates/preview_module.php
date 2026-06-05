<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $preview
 * @var string $sourcePath
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Modul-Update — Vorschau</h1>
    <?= $this->Html->link('&laquo; Abbrechen', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Modul</dt><dd class="col-sm-9"><code><?= h($preview['module_key']) ?></code></dd>
            <dt class="col-sm-3">Version</dt><dd class="col-sm-9"><?= h($preview['current_version'] ?? '?') ?> &rarr; <strong><?= h($preview['new_version'] ?? '?') ?></strong>
                <?php if (!empty($preview['is_downgrade'])): ?><span class="badge text-bg-danger">Downgrade</span><?php endif; ?>
                <?php if (!empty($preview['is_security'])): ?><span class="badge text-bg-danger">Sicherheitsupdate</span><?php endif; ?></dd>
            <dt class="col-sm-3">Ausstehende Migrationen</dt>
            <dd class="col-sm-9">
                <?php $pm = $preview['pending_migrations'] ?? []; ?>
                <?php if ($pm === []): ?><span class="text-muted">keine</span>
                <?php else: ?><ul class="mb-0"><?php foreach ($pm as $m): ?><li><code class="small"><?= h($m) ?></code></li><?php endforeach; ?></ul><?php endif; ?>
            </dd>
        </dl>

        <?php if (!empty($preview['errors'])): ?>
            <div class="alert alert-danger mt-3 mb-0">
                <strong>Blockierende Probleme:</strong>
                <ul class="mb-0"><?php foreach ($preview['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2">
        <?php if (!empty($preview['ok'])): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'module']]) ?>
                <?= $this->Form->hidden('module_key', ['value' => $preview['module_key']]) ?>
                <?= $this->Form->hidden('source_path', ['value' => $sourcePath]) ?>
                <?= $this->Form->button('Update jetzt ausführen', ['class' => 'btn btn-primary btn-sm', 'confirm' => 'Modul-Update jetzt ausführen?']) ?>
            <?= $this->Form->end() ?>
        <?php else: ?>
            <span class="text-danger small align-self-center">Update blockiert — bitte Probleme beheben.</span>
        <?php endif; ?>
        <?= $this->Html->link('Abbrechen', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>
</div>
