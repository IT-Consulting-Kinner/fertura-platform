<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $areas
 * @var array<int, array<string, mixed>> $groups
 */
$badge = ['active' => 'success', 'invited' => 'info', 'disabled' => 'secondary', 'anonymized' => 'dark'];
$anon = $user['status'] === 'anonymized';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h($user['username']) ?></h1>
    <div class="d-flex gap-2">
        <?php if ($user['status'] !== 'anonymized'): ?>
            <?= $this->Html->link('Bearbeiten', ['action' => 'edit', $user['id']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endif; ?>
        <?= $this->Html->link('&laquo; Zur Liste', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Stammdaten</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">E-Mail</dt><dd class="col-sm-8"><?= h($user['email']) ?></dd>
                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: '–' ?></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge text-bg-<?= $badge[$user['status']] ?? 'secondary' ?>"><?= h($user['status']) ?></span></dd>
                </dl>
                <?php if (!$anon): ?>
                <hr>
                <div class="d-flex gap-2">
                    <?php if ($user['status'] !== 'active'): ?>
                        <?= $this->Form->postLink('Aktivieren', ['action' => 'setStatus', $user['id'], 'active'], ['class' => 'btn btn-success btn-sm']) ?>
                    <?php else: ?>
                        <?= $this->Form->postLink('Deaktivieren', ['action' => 'setStatus', $user['id'], 'disabled'], ['class' => 'btn btn-warning btn-sm']) ?>
                    <?php endif; ?>
                    <?= $this->Form->postLink('Anonymisieren', ['action' => 'anonymize', $user['id']], ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => 'Benutzer irreversibel anonymisieren? Dies kann nicht rückgängig gemacht werden.']) ?>
                </div>
                <hr>
                <p class="mb-1 small text-muted">Einladung &amp; Passwort</p>
                <div class="d-flex gap-2 mb-2">
                    <?= $this->Form->postLink('Einladungslink erzeugen', ['action' => 'invite', $user['id']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
                <?= $this->Form->create(null, ['url' => ['action' => 'setPassword', $user['id']], 'class' => 'input-group input-group-sm']) ?>
                    <?= $this->Form->control('password', ['type' => 'password', 'label' => false, 'class' => 'form-control', 'placeholder' => 'Passwort direkt setzen', 'required' => true]) ?>
                    <?= $this->Form->button('Setzen', ['class' => 'btn btn-outline-secondary']) ?>
                <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header">Gruppen</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($groups as $g): ?>
                    <li class="list-group-item"><?= h($g['name']) ?></li>
                <?php endforeach; ?>
                <?php if ($groups === []): ?><li class="list-group-item text-muted">Keine Gruppen.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Administrationsbereiche</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($areas as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($a['label']) ?> <code class="small text-muted"><?= h($a['area_key']) ?></code></span>
                        <?php if (!$anon): ?>
                            <?php if (filter_var($a['held'], FILTER_VALIDATE_BOOLEAN)): ?>
                                <?= $this->Form->postLink('Entziehen', ['action' => 'toggleArea', $user['id'], $a['area_key']], ['class' => 'btn btn-outline-danger btn-sm']) ?>
                            <?php else: ?>
                                <?= $this->Form->postLink('Zuweisen', ['action' => 'toggleArea', $user['id'], $a['area_key']], ['class' => 'btn btn-outline-success btn-sm']) ?>
                            <?php endif; ?>
                        <?php elseif (filter_var($a['held'], FILTER_VALIDATE_BOOLEAN)): ?>
                            <span class="badge text-bg-success">aktiv</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
