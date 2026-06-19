<?php
/**
 * Self-service profile: own details + password change.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User|null $user
 * @var int $minPassword
 */
$this->assign('title', __('account.title'));
?>
<h1 class="h3 mb-4"><?= h(__('account.title')) ?></h1>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><?= h(__('account.data_heading')) ?></h2>
        <?= $this->Form->create($user, ['url' => '/account/update']) ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label text-muted small"><?= h(__('account.username')) ?></label>
                <input class="form-control" value="<?= h((string)($user?->get('username') ?? '')) ?>" disabled>
            </div>
            <div class="col-md-6">
                <label class="form-label text-muted small"><?= h(__('account.email')) ?></label>
                <input class="form-control" value="<?= h((string)($user?->get('email') ?? '')) ?>" disabled>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('salutation', ['type' => 'text', 'label' => __('account.salutation'), 'class' => 'form-control', 'required' => false]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('first_name', ['type' => 'text', 'label' => __('account.first_name'), 'class' => 'form-control', 'required' => false]) ?>
            </div>
            <div class="col-md-4">
                <?= $this->Form->control('last_name', ['type' => 'text', 'label' => __('account.last_name'), 'class' => 'form-control', 'required' => false]) ?>
            </div>
        </div>
        <div class="mt-3"><?= $this->Form->button(__('account.save'), ['class' => 'btn btn-primary']) ?></div>
        <?= $this->Form->end() ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5 mb-3"><?= h(__('account.password_heading')) ?></h2>
        <?= $this->Form->create(null, ['url' => '/account/password']) ?>
        <div class="row g-3">
            <div class="col-md-12">
                <?= $this->Form->control('current_password', ['type' => 'password', 'label' => __('account.current_password'), 'class' => 'form-control', 'autocomplete' => 'current-password']) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('new_password', ['type' => 'password', 'label' => __('account.new_password'), 'class' => 'form-control', 'autocomplete' => 'new-password']) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('confirm_password', ['type' => 'password', 'label' => __('account.confirm_password'), 'class' => 'form-control', 'autocomplete' => 'new-password']) ?>
            </div>
        </div>
        <p class="text-muted small mt-2 mb-2"><?= h(__('account.password_hint', $minPassword)) ?></p>
        <?= $this->Form->button(__('account.change_password'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>
