<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $entries
 * @var array<int, array<string, mixed>> $actions
 * @var array<int, array<string, mixed>> $entityTypes
 * @var string $action
 * @var string $entityType
 * @var string $moduleKey
 */
?>
<h1 class="h3 mb-3">Audit-Log</h1>

<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end mb-3']) ?>
    <div class="col-auto"><label class="form-label small mb-0">Aktion</label>
        <?= $this->Form->control('action', ['label' => false, 'value' => $action, 'class' => 'form-control form-control-sm', 'placeholder' => 'enthält …']) ?></div>
    <div class="col-auto"><label class="form-label small mb-0">Entitätstyp</label>
        <?= $this->Form->select('entity_type', array_column($entityTypes, 'entity_type', 'entity_type'),
            ['value' => $entityType, 'empty' => '— alle —', 'class' => 'form-select form-select-sm']) ?></div>
    <div class="col-auto"><label class="form-label small mb-0">Modul</label>
        <?= $this->Form->control('module_key', ['label' => false, 'value' => $moduleKey, 'class' => 'form-control form-control-sm']) ?></div>
    <div class="col-auto">
        <?= $this->Form->button('Filtern', ['class' => 'btn btn-primary btn-sm']) ?>
        <?= $this->Html->link('Zurücksetzen', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
    </div>
<?= $this->Form->end() ?>

<p class="text-muted small">Letzte 100 Einträge (neueste zuerst).</p>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Zeitpunkt</th><th>Akteur</th><th>Aktion</th><th>Entität</th><th>Modul</th><th>Komponente</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td class="small text-nowrap"><?= h((string)$e['created_at']) ?></td>
            <td><?= $e['actor_username'] !== null ? h($e['actor_username']) : '<span class="text-muted small">' . h((string)$e['actor_user_id']) . '</span>' ?></td>
            <td><code class="small"><?= h($e['action']) ?></code></td>
            <td class="small"><?= h($e['entity_type']) ?><?php if (!empty($e['entity_label'])): ?>: <?= h($e['entity_label']) ?><?php elseif (!empty($e['entity_id'])): ?> <span class="text-muted"><?= h((string)$e['entity_id']) ?></span><?php endif; ?></td>
            <td class="small"><?= h($e['module_key']) ?: '–' ?></td>
            <td class="small"><?= h($e['component']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><tr><td colspan="6" class="text-muted">Keine Einträge.</td></tr><?php endif; ?>
    </tbody>
</table>
