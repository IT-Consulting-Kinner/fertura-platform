<?php
/**
 * @var \App\View\AppView $this
 */
?>
<?= $this->Form->create(null, ['url' => '/login']) ?>
    <div class="mb-3">
        <label class="form-label" for="username">Benutzername</label>
        <?= $this->Form->control('username', ['label' => false, 'class' => 'form-control', 'autofocus' => true, 'required' => true]) ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Passwort</label>
        <?= $this->Form->control('password', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true]) ?>
    </div>
    <?= $this->Form->button('Anmelden', ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
