<?php
/**
 * @var \App\View\AppView $this
 */
?>
<h1 class="h4 mb-3 text-center"><?= __('auth.forgot.title') ?></h1>
<p class="text-muted small"><?= __('auth.forgot.intro') ?></p>
<?= $this->Form->create(null, ['url' => '/forgot-password']) ?>
    <div class="mb-3">
        <label class="form-label" for="identifier"><?= __('auth.forgot.identifier') ?></label>
        <?= $this->Form->control('identifier', ['label' => false, 'class' => 'form-control', 'required' => true, 'autofocus' => true]) ?>
    </div>
    <?= $this->Form->button(__('auth.forgot.submit'), ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
<p class="text-center mt-3 mb-0"><a href="/login"><?= __('auth.backtologin') ?></a></p>
