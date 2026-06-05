<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $users
 */
$badge = ['active' => 'success', 'invited' => 'info', 'disabled' => 'secondary', 'anonymized' => 'dark'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Benutzer</h1>
    <?= $this->Html->link('Neuer Benutzer', ['action' => 'add'], ['class' => 'btn btn-primary btn-sm']) ?>
</div>
<table class="table table-hover align-middle">
    <thead><tr><th>Benutzername</th><th>Name</th><th>E-Mail</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= h($u['username']) ?></td>
            <td><?= h(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><span class="badge text-bg-<?= $badge[$u['status']] ?? 'secondary' ?>"><?= h($u['status']) ?></span></td>
            <td class="text-end"><?= $this->Html->link('Details', ['action' => 'view', $u['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?>
        <tr><td colspan="5" class="text-muted">Keine Benutzer vorhanden.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
