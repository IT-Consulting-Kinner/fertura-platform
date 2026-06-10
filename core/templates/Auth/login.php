<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{id:string,type:string,name:string,button_label:string}> $ssoProviders
 * @var array{name:string,brand_name:?string,logo_url:?string}|null $tenantBranding
 */
?>
<?php if (!empty($tenantBranding) && ($tenantBranding['brand_name'] || $tenantBranding['logo_url'])): ?>
    <div class="text-center mb-4">
        <?php if ($tenantBranding['logo_url']): ?>
            <img src="<?= h($tenantBranding['logo_url']) ?>" alt="<?= h($tenantBranding['brand_name'] ?? $tenantBranding['name']) ?>" style="max-height:64px" class="mb-2">
        <?php endif; ?>
        <?php if ($tenantBranding['brand_name']): ?>
            <div class="h5 mb-0"><?= h($tenantBranding['brand_name']) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!empty($ssoProviders)): ?>
    <div class="d-grid gap-2 mb-3">
        <?php foreach ($ssoProviders as $p): ?>
            <a class="btn btn-outline-primary" href="/sso/start/<?= h($p['id']) ?>"><?= h($p['button_label']) ?></a>
        <?php endforeach; ?>
    </div>
    <hr class="my-3">
<?php endif; ?>
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
