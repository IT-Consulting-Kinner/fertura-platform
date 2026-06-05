<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $licenses
 */
$evalBadge = ['valid' => 'success', 'grace' => 'warning', 'needs_online' => 'warning', 'expired' => 'danger', 'revoked' => 'danger', 'missing' => 'secondary'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Lizenzen</h1>
    <?= $this->Html->link('&laquo; Marketplace', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>

<table class="table table-hover align-middle">
    <thead><tr><th>Modul</th><th>Schlüssel-ID</th><th>Gültig bis</th><th>Online</th><th>Status (DB)</th><th>Bewertung</th></tr></thead>
    <tbody>
    <?php foreach ($licenses as $l): ?>
        <tr>
            <td><code><?= h($l['module_key']) ?></code></td>
            <td class="small text-muted"><?= h($l['signed_key_id']) ?></td>
            <td><?= h((string)$l['valid_to']) ?: '–' ?></td>
            <td><?= filter_var($l['online_enforcement'], FILTER_VALIDATE_BOOLEAN) ? 'ja' : 'nein' ?></td>
            <td><?= h($l['status']) ?></td>
            <td><span class="badge text-bg-<?= $evalBadge[$l['evaluated']] ?? 'secondary' ?>"><?= h($l['evaluated']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($licenses === []): ?><tr><td colspan="6" class="text-muted">Keine Lizenzen installiert.</td></tr><?php endif; ?>
    </tbody>
</table>

<div class="card mt-4"><div class="card-header">Lizenz installieren</div><div class="card-body">
    <?= $this->Form->create(null, ['url' => ['action' => 'uploadLicense'], 'type' => 'file']) ?>
    <div class="mb-3"><label class="form-label">Lizenzdatei (.json)</label>
        <?= $this->Form->control('license_file', ['type' => 'file', 'label' => false, 'class' => 'form-control', 'accept' => 'application/json,.json']) ?></div>
    <div class="mb-3"><label class="form-label">… oder Lizenz-JSON einfügen</label>
        <?= $this->Form->control('license_json', ['type' => 'textarea', 'label' => false, 'class' => 'form-control font-monospace', 'rows' => 4]) ?></div>
    <?= $this->Form->button('Installieren', ['class' => 'btn btn-primary btn-sm']) ?>
    <?= $this->Form->end() ?>
</div></div>
