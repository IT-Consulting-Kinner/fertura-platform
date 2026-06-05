<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $preview
 * @var bool $force
 */
$incompat = $preview['incompatible_modules'] ?? [];
$blocked = $incompat !== [] && !$force;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Core-Update — Vorschau</h1>
    <?= $this->Html->link('&laquo; Abbrechen', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<div class="card">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Version</dt><dd class="col-sm-9"><?= h($preview['current_version'] ?? '?') ?> &rarr; <strong><?= h($preview['target_version'] ?? '?') ?></strong></dd>
            <dt class="col-sm-3">Inkompatible Module</dt>
            <dd class="col-sm-9"><?php if ($incompat === []): ?><span class="text-muted">keine</span>
                <?php else: ?><span class="text-danger"><?= h(implode(', ', $incompat)) ?></span><?php endif; ?></dd>
            <dt class="col-sm-3">Ausstehende Core-Migrationen</dt>
            <dd class="col-sm-9"><?php $pm = $preview['pending_migrations'] ?? []; ?>
                <?php if ($pm === []): ?><span class="text-muted">keine</span>
                <?php else: ?><ul class="mb-0"><?php foreach ($pm as $m): ?><li><code class="small"><?= h($m) ?></code></li><?php endforeach; ?></ul><?php endif; ?></dd>
        </dl>
        <?php if (!empty($preview['errors'])): ?>
            <div class="alert alert-danger mt-3 mb-0"><ul class="mb-0"><?php foreach ($preview['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($blocked): ?>
            <div class="alert alert-warning mt-3 mb-0">Inkompatible Module vorhanden — Ausführung nur mit „Erzwingen".</div>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2">
        <?php if (empty($preview['errors'])): ?>
            <?= $this->Form->create(null, ['url' => ['action' => 'core']]) ?>
                <?= $this->Form->hidden('target_version', ['value' => $preview['target_version']]) ?>
                <?= $this->Form->hidden('force', ['value' => $force ? '1' : '0']) ?>
                <?= $this->Form->button($blocked ? 'Trotzdem erzwingen' : 'Update jetzt ausführen',
                    ['class' => 'btn ' . ($blocked ? 'btn-danger' : 'btn-warning') . ' btn-sm', 'confirm' => 'Core-Update jetzt ausführen?']) ?>
            <?= $this->Form->end() ?>
        <?php endif; ?>
        <?= $this->Html->link('Abbrechen', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>
</div>
