<?php
/**
 * @var \App\View\AppView $this
 */
?>
<?= $this->Form->create(null, ['url' => '/login']) ?>
    <div class="mb-3">
        <label class="form-label" for="username"><?= __('auth.login.username') ?></label>
        <?= $this->Form->control('username', ['label' => false, 'class' => 'form-control', 'autofocus' => true, 'required' => true]) ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password"><?= __('auth.login.password') ?></label>
        <?= $this->Form->control('password', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true]) ?>
    </div>
    <?= $this->Form->button(__('auth.login.submit'), ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
<p class="text-center mt-3 mb-0"><a href="/forgot-password"><?= __('auth.login.forgot') ?></a></p>
