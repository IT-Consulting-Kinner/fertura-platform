<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var int $minLength
 */
?>
<h1 class="h4 mb-3 text-center"><?= __('auth.setpw.title') ?></h1>
<?php if ($token === ''): ?>
    <p class="text-danger"><?= __('auth.setpw.notoken') ?></p>
<?php else: ?>
    <?= $this->Form->create(null, ['url' => '/set-password']) ?>
        <?= $this->Form->hidden('token', ['value' => $token]) ?>
        <div class="mb-3">
            <label class="form-label" for="password"><?= __('auth.setpw.password', (int)$minLength) ?></label>
            <?= $this->Form->control('password', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true, 'autofocus' => true]) ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password-confirm"><?= __('auth.setpw.confirm') ?></label>
            <?= $this->Form->control('password_confirm', ['label' => false, 'type' => 'password', 'class' => 'form-control', 'required' => true]) ?>
        </div>
        <?= $this->Form->button(__('auth.setpw.submit'), ['class' => 'btn btn-primary w-100']) ?>
    <?= $this->Form->end() ?>
<?php endif; ?>
<p class="text-center mt-3 mb-0"><a href="/login"><?= __('auth.backtologin') ?></a></p>
