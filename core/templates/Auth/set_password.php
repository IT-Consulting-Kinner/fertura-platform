<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var int $minLength
 */
?>
<h1 class="h4 mb-3 text-center">Passwort setzen</h1>
<?php if ($token === ''): ?>
    <p class="text-danger">Kein Token angegeben. Bitte den vollständigen Einladungslink verwenden.</p>
<?php else: ?>
    <?= $this->Form->create(null, ['url' => '/set-password']) ?>
        <?= $this->Form->hidden('token', ['value' => $token]) ?>
        <div class="mb-3">
            <label class="form-label" for="password">Neues Passwort (min. <?= (int)$minLength ?> Zeichen)</label>
            <?= $this->Form->control('password', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true, 'autofocus' => true]) ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password-confirm">Passwort bestätigen</label>
            <?= $this->Form->control('password_confirm', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true]) ?>
        </div>
        <?= $this->Form->button('Passwort setzen', ['class' => 'btn btn-primary w-100']) ?>
    <?= $this->Form->end() ?>
<?php endif; ?>
<p class="text-center mt-3 mb-0"><a href="/login">Zur Anmeldung</a></p>
