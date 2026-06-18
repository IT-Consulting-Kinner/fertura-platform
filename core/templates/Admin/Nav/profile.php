<?php
/**
 * Self-service profile: the current admin's own account details.
 *
 * @var \App\View\AppView $this
 * @var array<string,mixed> $profile
 * @var list<string> $areas
 */
$this->assign('title', __('admin.nav.profile'));
$name = trim((string)($profile['first_name'] ?? '') . ' ' . (string)($profile['last_name'] ?? ''));
?>
<h1 class="h3 mb-4"><?= h(__('admin.nav.profile')) ?></h1>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4"><?= h(__('admin.profile.username')) ?></dt>
                <dd class="col-sm-8"><?= h((string)($profile['username'] ?? '')) ?></dd>
                <dt class="col-sm-4"><?= h(__('admin.profile.email')) ?></dt>
                <dd class="col-sm-8"><?= h((string)($profile['email'] ?? '')) ?></dd>
                <dt class="col-sm-4"><?= h(__('admin.profile.name')) ?></dt>
                <dd class="col-sm-8"><?= $name !== '' ? h($name) : '–' ?></dd>
                <dt class="col-sm-4"><?= h(__('admin.profile.status')) ?></dt>
                <dd class="col-sm-8"><?= h((string)($profile['status'] ?? '')) ?></dd>
                <dt class="col-sm-4"><?= h(__('admin.profile.locale')) ?></dt>
                <dd class="col-sm-8"><?= h((string)($profile['locale'] ?? '')) ?: '–' ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <h2 class="h6"><?= h(__('admin.profile.areas')) ?></h2>
            <?php if ($areas === []): ?>
                <p class="text-muted mb-0">–</p>
            <?php else: ?>
                <ul class="mb-0"><?php foreach ($areas as $a): ?><li><code><?= h($a) ?></code></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div></div>
    </div>
</div>
<p class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="/logout"><?= h(__('admin.nav.logout')) ?></a></p>
