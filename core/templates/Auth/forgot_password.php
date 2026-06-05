<?php
/**
 * @var \App\View\AppView $this
 */
?>
<h1 class="h4 mb-3 text-center">Passwort vergessen</h1>
<p class="text-muted small">Geben Sie Ihren Benutzernamen oder Ihre E-Mail-Adresse ein. Sie erhalten – sofern ein Konto existiert – eine E-Mail mit einem Link zum Zurücksetzen.</p>
<?= $this->Form->create(null, ['url' => '/forgot-password']) ?>
    <div class="mb-3">
        <label class="form-label" for="identifier">Benutzername oder E-Mail</label>
        <?= $this->Form->control('identifier', ['label' => false, 'class' => 'form-control', 'required' => true, 'autofocus' => true]) ?>
    </div>
    <?= $this->Form->button('Link anfordern', ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
<p class="text-center mt-3 mb-0"><a href="/login">Zur Anmeldung</a></p>
