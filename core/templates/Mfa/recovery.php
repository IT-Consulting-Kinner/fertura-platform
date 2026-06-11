<?php
/**
 * @var \App\View\AppView $this
 * @var list<string> $recoveryCodes
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('mfa.recovery.title')) ?></h1>
</div>
<div class="card"><div class="card-body" style="max-width:560px">
    <p class="text-success fw-semibold"><?= h(__('mfa.recovery.enabled')) ?></p>
    <p class="text-muted"><?= h(__('mfa.recovery.hint')) ?></p>
    <ul class="list-unstyled">
        <?php foreach ($recoveryCodes as $code): ?>
            <li><code class="user-select-all fs-6"><?= h($code) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p class="text-danger"><?= h(__('mfa.recovery.once')) ?></p>
    <a class="btn btn-primary" href="/mfa"><?= h(__('mfa.recovery.done')) ?></a>
</div></div>
