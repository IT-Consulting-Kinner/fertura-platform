<?php
/**
 * @var \App\View\AppView $this
 */
$currentUser = $currentUser ?? null;
$activeTop = $activeTop ?? '';
$topLink = static function (string $key, string $url, string $label) use ($activeTop): string {
    $active = $activeTop === $key;

    return sprintf(
        '<li class="nav-item"><a class="nav-link%s"%s href="%s">%s</a></li>',
        $active ? ' active' : '',
        $active ? ' aria-current="page"' : '',
        h($url),
        h($label),
    );
};
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', \Cake\I18n\I18n::getLocale())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: 'Fertura Admin' ?></title>
    <?= $this->Html->css('bootstrap.min') ?>
    <style>
        body { background:#f6f7f9; }
        /* A11y: sichtbarer Fokus-Indikator + Skip-Link, der erst bei Fokus erscheint. */
        a:focus-visible, button:focus-visible, .form-control:focus-visible, .form-select:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
        .navbar .nav-link.active { font-weight:600; color:#fff; }
        a.card:hover { border-color:#0d6efd; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<nav class="navbar navbar-expand navbar-dark bg-dark px-3" aria-label="<?= h(__('a11y.nav_main')) ?>">
    <a class="navbar-brand" href="/admin">Fertura <span class="text-secondary">Admin</span></a>
    <ul class="navbar-nav me-auto">
        <?= $topLink('dashboard', '/admin', __('admin.nav.dashboard')) ?>
        <?= $topLink('module', '/admin/module', __('admin.nav.modules')) ?>
        <?= $topLink('administration', '/admin/administration', __('admin.nav.administration')) ?>
    </ul>
    <div class="d-flex align-items-center text-light gap-2">
        <?= $this->cell('LocaleSwitcher', [true]) ?>
        <?php if ($currentUser !== null): ?>
            <span class="ms-1 small"><?= h($currentUser->get('username')) ?></span>
            <a class="btn btn-sm btn-outline-light" href="/logout"><?= __('admin.nav.logout') ?></a>
        <?php endif; ?>
    </div>
</nav>
<main id="main" tabindex="-1" class="container-fluid p-4">
    <div aria-live="polite"><?= $this->Flash->render() ?></div>
    <?= $this->fetch('content') ?>
</main>
</body>
</html>
