<?php
/**
 * @var \App\View\AppView $this
 * @var bool $enabled
 * @var int $recoveryLeft
 * @var bool $required
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('mfa.index.title')) ?></h1>
</div>
<div class="card"><div class="card-body" style="max-width:560px">
<?php if ($enabled): ?>
    <p>
        <span class="badge text-bg-success"><?= h(__('mfa.index.enabled')) ?></span>
        <span class="ms-2 text-muted"><?= h(__('mfa.index.recovery_left', (string)$recoveryLeft)) ?></span>
    </p>
    <?= $this->Form->create(null, ['url' => '/mfa/disable']) ?>
        <div class="mb-3">
            <label class="form-label" for="code"><?= h(__('mfa.index.disable_code')) ?></label>
            <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control', 'required' => true, 'autocomplete' => 'one-time-code']) ?>
        </div>
        <?= $this->Form->button(__('mfa.index.disable'), ['class' => 'btn btn-outline-danger']) ?>
    <?= $this->Form->end() ?>
<?php else: ?>
    <p>
        <span class="badge text-bg-secondary"><?= h(__('mfa.index.disabled')) ?></span>
        <?php if ($required): ?>
            <span class="ms-2 text-danger"><?= h(__('mfa.index.required_hint')) ?></span>
        <?php endif; ?>
    </p>
    <p class="text-muted"><?= h(__('mfa.index.intro')) ?></p>
    <?= $this->Form->create(null, ['url' => '/mfa/setup']) ?>
        <?= $this->Form->button(__('mfa.index.enable'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
<?php endif; ?>
</div></div>
