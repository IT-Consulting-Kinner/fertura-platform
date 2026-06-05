<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $history
 * @var array<int, array<string, mixed>> $modules
 * @var string $coreVersion
 */
$resBadge = ['success' => 'success', 'failed' => 'danger', 'rolled_back' => 'warning'];
?>
<h1 class="h3 mb-3">Update-Manager</h1>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card"><div class="card-header">Modul aktualisieren</div><div class="card-body">
            <?= $this->Form->create(null, ['url' => ['action' => 'module']]) ?>
            <div class="mb-2"><label class="form-label">Modul</label>
                <?= $this->Form->select('module_key', array_combine(array_column($modules, 'module_key'),
                    array_map(fn ($m) => $m['module_key'] . ' (' . $m['version'] . ')', $modules)),
                    ['class' => 'form-select', 'empty' => '— wählen —', 'required' => true]) ?></div>
            <div class="mb-2"><label class="form-label">Paketpfad (signiert)</label>
                <?= $this->Form->control('source_path', ['label' => false, 'class' => 'form-control', 'placeholder' => '/pfad/zum/paket', 'required' => true]) ?></div>
            <?= $this->Form->button('Aktualisieren', ['class' => 'btn btn-primary btn-sm', 'confirm' => 'Modul-Update jetzt ausführen?']) ?>
            <?= $this->Form->end() ?>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card"><div class="card-header">Core aktualisieren <span class="text-muted small">aktuell: <?= h($coreVersion) ?></span></div><div class="card-body">
            <?= $this->Form->create(null, ['url' => ['action' => 'core']]) ?>
            <div class="mb-2"><label class="form-label">Zielversion</label>
                <?= $this->Form->control('target_version', ['label' => false, 'class' => 'form-control', 'placeholder' => 'z. B. 1.1.0', 'required' => true]) ?></div>
            <div class="form-check mb-2"><?= $this->Form->checkbox('force', ['class' => 'form-check-input', 'id' => 'force']) ?>
                <label class="form-check-label" for="force">Erzwingen (Kompatibilitätsprüfung übergehen)</label></div>
            <?= $this->Form->button('Core aktualisieren', ['class' => 'btn btn-warning btn-sm', 'confirm' => 'Core-Update jetzt ausführen?']) ?>
            <?= $this->Form->end() ?>
        </div></div>
    </div>
</div>

<h2 class="h5">Historie</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Zeitpunkt</th><th>Typ</th><th>Komponente</th><th>Von</th><th>Nach</th><th>Ergebnis</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td class="small"><?= h((string)$h['executed_at']) ?></td>
            <td><?= h($h['component_type']) ?></td>
            <td><code><?= h($h['component_key']) ?></code></td>
            <td><?= h($h['old_version']) ?: '–' ?></td>
            <td><?= h($h['new_version']) ?: '–' ?></td>
            <td><span class="badge text-bg-<?= $resBadge[$h['result']] ?? 'secondary' ?>"><?= h($h['result']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($history === []): ?><tr><td colspan="6" class="text-muted">Keine Updates protokolliert.</td></tr><?php endif; ?>
    </tbody>
</table>
