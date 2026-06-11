<?php
/**
 * @var \App\View\AppView $this
 * @var string $secret
 * @var string $otpauthUri
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('mfa.setup.title')) ?></h1>
</div>
<div class="card"><div class="card-body" style="max-width:560px">
    <ol class="mb-3">
        <li><?= h(__('mfa.setup.step_app')) ?></li>
        <li><?= h(__('mfa.setup.step_key')) ?></li>
        <li><?= h(__('mfa.setup.step_confirm')) ?></li>
    </ol>
    <p>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h($otpauthUri) ?>"><?= h(__('mfa.setup.open_app')) ?></a>
    </p>
    <p class="mb-1"><?= h(__('mfa.setup.manual_key')) ?></p>
    <p><code class="user-select-all fs-5"><?= h(trim(chunk_split($secret, 4, ' '))) ?></code></p>
    <?= $this->Form->create(null, ['url' => '/mfa/confirm']) ?>
        <div class="mb-3">
            <label class="form-label" for="code"><?= h(__('mfa.setup.code')) ?></label>
            <?= $this->Form->control('code', [
                'label' => false, 'class' => 'form-control', 'required' => true,
                'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'autofocus' => true,
            ]) ?>
        </div>
        <?= $this->Form->button(__('mfa.setup.submit'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
</div></div>
