<?php
/**
 * Core-controlled layout for module-provided web pages (ch. 23.16.3, web-mount).
 *
 * A module page renders inside this Core layout: the Core owns the document
 * shell, branding, locale switcher, session controls, flash area and the main
 * landmark; the module template only fills the `content` block. This keeps the
 * presentation surface (and its a11y/CSP/security posture) under Core control.
 *
 * @var \App\View\AppView $this
 * @var \Authentication\IdentityInterface|null $currentUser
 * @var string $moduleKey
 * @var string $moduleTitle
 */
$currentUser = $currentUser ?? null;
$moduleKey = $moduleKey ?? '';
$moduleTitle = $moduleTitle ?? '';
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', \Cake\I18n\I18n::getLocale())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->fetch('title') ?: h($moduleTitle !== '' ? $moduleTitle : 'Fertura') ?></title>
    <?= $this->Html->css('bootstrap.min') ?>
    <style>
        body { background:#f6f7f9; }
        a:focus-visible, button:focus-visible, .form-control:focus-visible, .form-select:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<nav class="navbar navbar-dark bg-dark px-3" aria-label="<?= h(__('a11y.nav_main')) ?>">
    <a class="navbar-brand" href="/m/<?= h($moduleKey) ?>">Fertura<?= $moduleTitle !== '' ? ' <span class="text-secondary">' . h($moduleTitle) . '</span>' : '' ?></a>
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
