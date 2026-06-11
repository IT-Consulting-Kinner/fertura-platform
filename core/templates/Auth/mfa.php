<?php
/**
 * @var \App\View\AppView $this
 */
?>
<h1 class="h5 mb-3"><?= h(__('mfa.challenge.title')) ?></h1>
<p class="text-muted"><?= h(__('mfa.challenge.hint')) ?></p>
<?= $this->Form->create(null, ['url' => '/login/mfa']) ?>
    <div class="mb-3">
        <label class="form-label" for="code"><?= h(__('mfa.challenge.code')) ?></label>
        <?= $this->Form->control('code', [
            'label' => false, 'class' => 'form-control', 'autofocus' => true, 'required' => true,
            'autocomplete' => 'one-time-code', 'inputmode' => 'numeric',
        ]) ?>
        <div class="form-text"><?= h(__('mfa.challenge.recovery_hint')) ?></div>
    </div>
    <?= $this->Form->button(__('mfa.challenge.submit'), ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
<p class="text-center mt-3 mb-0"><a href="/login"><?= h(__('mfa.challenge.back')) ?></a></p>
