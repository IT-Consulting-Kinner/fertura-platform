<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $group
 * @var array<int, array<string, mixed>> $members
 * @var array<int, array<string, mixed>> $candidates
 * @var array<int, array<string, mixed>> $permissions
 * @var array<int, array<string, mixed>> $resources
 */
$active = filter_var($group['active'], FILTER_VALIDATE_BOOLEAN);
$resOptions = [];
foreach ($resources as $r) {
    $resOptions[$r['module_key'] . '::' . $r['resource_type']] = $r['resource_name'] . ' (' . $r['module_key'] . ')';
}
$flag = static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? '✓' : '–';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h($group['name']) ?>
        <span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?> align-middle"><?= $active ? 'aktiv' : 'inaktiv' ?></span></h1>
    <div class="d-flex gap-2">
        <?= $this->Form->postLink($active ? 'Deaktivieren' : 'Aktivieren',
            ['action' => 'setActive', $group['id'], $active ? 'off' : 'on'],
            ['class' => 'btn btn-sm ' . ($active ? 'btn-warning' : 'btn-success')]) ?>
        <?= $this->Html->link('&laquo; Zur Liste', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Mitglieder</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($members as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($m['username']) ?> <span class="text-muted small"><?= h($m['email']) ?></span></span>
                        <?= $this->Form->postLink('Entfernen', ['action' => 'removeMember', $group['id'], $m['id']], ['class' => 'btn btn-outline-danger btn-sm']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if ($members === []): ?><li class="list-group-item text-muted">Keine Mitglieder.</li><?php endif; ?>
            </ul>
            <div class="card-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'addMember', $group['id']]]) ?>
                <div class="input-group input-group-sm">
                    <?= $this->Form->select('user_id', array_column($candidates, 'username', 'id'),
                        ['empty' => '— Benutzer wählen —', 'class' => 'form-select']) ?>
                    <?= $this->Form->button('Hinzufügen', ['class' => 'btn btn-primary']) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">Ressourcenrechte (BREAD)</div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Modul / Ressource</th><th class="text-center">B</th><th class="text-center">R</th><th class="text-center">A</th><th class="text-center">E</th><th class="text-center">D</th></tr></thead>
                <tbody>
                <?php foreach ($permissions as $p): ?>
                    <tr>
                        <td><code class="small"><?= h($p['module_key']) ?>::<?= h($p['resource_type']) ?></code></td>
                        <td class="text-center"><?= $flag($p['can_browse']) ?></td>
                        <td class="text-center"><?= $flag($p['can_read']) ?></td>
                        <td class="text-center"><?= $flag($p['can_add']) ?></td>
                        <td class="text-center"><?= $flag($p['can_edit']) ?></td>
                        <td class="text-center"><?= $flag($p['can_delete']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($permissions === []): ?><tr><td colspan="6" class="text-muted">Keine Rechte vergeben.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="card-body border-top">
                <?php if ($resOptions === []): ?>
                    <p class="text-muted mb-0">Keine registrierten Ressourcen. Module mit Ressourcen müssen erst installiert werden.</p>
                <?php else: ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'setPermission', $group['id']]]) ?>
                    <div class="mb-2"><?= $this->Form->select('resource', $resOptions, ['class' => 'form-select form-select-sm', 'empty' => '— Ressource —', 'required' => true]) ?></div>
                    <div class="d-flex gap-3 mb-2 small">
                        <?php foreach (['browse' => 'B', 'read' => 'R', 'add' => 'A', 'edit' => 'E', 'delete' => 'D'] as $a => $lbl): ?>
                            <div class="form-check"><?= $this->Form->checkbox('can_' . $a, ['class' => 'form-check-input', 'id' => 'p_' . $a]) ?>
                                <label class="form-check-label" for="p_<?= $a ?>"><?= $lbl ?></label></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted small mb-2">Alle Häkchen leer = Rechte entziehen. Vergabe gilt für die Objektklasse (resource_key = *).</p>
                    <?= $this->Form->button('Rechte setzen', ['class' => 'btn btn-primary btn-sm']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
