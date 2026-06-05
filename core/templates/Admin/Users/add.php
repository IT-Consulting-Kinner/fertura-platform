<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Neuer Benutzer</h1>
    <?= $this->Html->link('&laquo; Zur Liste', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]) ?>
</div>
<div class="card"><div class="card-body" style="max-width:520px">
    <?= $this->Form->create($user) ?>
    <div class="mb-3"><?= $this->Form->control('username', ['class' => 'form-control', 'label' => 'Benutzername']) ?></div>
    <div class="mb-3"><?= $this->Form->control('email', ['class' => 'form-control', 'label' => 'E-Mail']) ?></div>
    <div class="row">
        <div class="col mb-3"><?= $this->Form->control('first_name', ['class' => 'form-control', 'label' => 'Vorname']) ?></div>
        <div class="col mb-3"><?= $this->Form->control('last_name', ['class' => 'form-control', 'label' => 'Nachname']) ?></div>
    </div>
    <p class="text-muted small">Der Benutzer wird mit Status <strong>invited</strong> angelegt und erhält per Einladung ein Passwort.</p>
    <?= $this->Form->button('Anlegen', ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div></div>
