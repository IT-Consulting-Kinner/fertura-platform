<?php
/**
 * Standalone shell for the self-service account page — usable by ANY logged-in
 * user (admin or not), so it carries no admin navigation. Top bar = brand,
 * language select, current user + logout.
 *
 * @var \App\View\AppView $this
 */
$currentUser = $currentUser ?? null;
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', \Cake\I18n\I18n::getLocale())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: 'Fertura' ?></title>
    <?= $this->Html->css('bootstrap.min') ?>
    <style>
        body { background:#f6f7f9; }
        a:focus-visible, button:focus-visible, .form-control:focus-visible, .form-select:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
        .locale-select { max-width:11rem; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<nav class="navbar navbar-dark bg-dark px-3" aria-label="<?= h(__('a11y.nav_main')) ?>">
    <span class="navbar-brand mb-0">Fertura</span>
    <div class="d-flex align-items-center text-light gap-2 ms-auto">
        <?= $this->cell('LocaleSwitcher', [true, 'select']) ?>
        <?php if ($currentUser !== null): ?>
            <span class="small"><?= h(__('admin.nav.user_prefix')) ?><?= h($currentUser->get('username')) ?></span>
            <a class="btn btn-sm btn-outline-light" href="/logout"><?= h(__('admin.nav.logout')) ?></a>
        <?php endif; ?>
    </div>
</nav>
<main id="main" tabindex="-1" class="container py-4" style="max-width:720px;">
    <div aria-live="polite"><?= $this->Flash->render() ?></div>
    <?= $this->fetch('content') ?>
</main>
</body>
</html>
