<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $groups
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Gruppen</h1>
    <?= $this->Html->link('Neue Gruppe', ['action' => 'add'], ['class' => 'btn btn-primary btn-sm']) ?>
</div>
<table class="table table-hover align-middle">
    <thead><tr><th>Name</th><th>Beschreibung</th><th>Mitglieder</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
        <tr>
            <td><?= h($g['name']) ?></td>
            <td class="text-muted"><?= h($g['description']) ?: '–' ?></td>
            <td><?= (int)$g['member_count'] ?></td>
            <td><?php $a = filter_var($g['active'], FILTER_VALIDATE_BOOLEAN); ?>
                <span class="badge text-bg-<?= $a ? 'success' : 'secondary' ?>"><?= $a ? 'aktiv' : 'inaktiv' ?></span></td>
            <td class="text-end"><?= $this->Html->link('Verwalten', ['action' => 'view', $g['id']], ['class' => 'btn btn-outline-secondary btn-sm']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($groups === []): ?><tr><td colspan="5" class="text-muted">Keine Gruppen vorhanden.</td></tr><?php endif; ?>
    </tbody>
</table>
