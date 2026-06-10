<?php
/**
 * @var \App\View\AppView $this
 */
$navAreas = $navAreas ?? [];
$currentUser = $currentUser ?? null;
$activeArea = $activeArea ?? null;
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
        .sidebar { min-height: calc(100vh - 56px); }
        .sidebar .nav-link.active { font-weight:600; }
        /* A11y: sichtbarer Fokus-Indikator + Skip-Link, der erst bei Fokus erscheint. */
        a:focus-visible, button:focus-visible, .form-control:focus-visible, .form-select:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<nav class="navbar navbar-dark bg-dark px-3" aria-label="<?= h(__('a11y.nav_main')) ?>">
    <a class="navbar-brand" href="/admin">Fertura <span class="text-secondary">Admin</span></a>
    <div class="d-flex align-items-center text-light gap-2">
        <?= $this->cell('LocaleSwitcher', [true]) ?>
        <?php if ($currentUser !== null): ?>
            <span class="ms-1 small"><?= h($currentUser->get('username')) ?></span>
            <a class="btn btn-sm btn-outline-light" href="/logout"><?= __('admin.nav.logout') ?></a>
        <?php endif; ?>
    </div>
</nav>
<div class="container-fluid">
    <div class="row">
        <aside class="col-md-3 col-lg-2 bg-white border-end sidebar p-3">
            <nav aria-label="<?= h(__('a11y.nav_admin')) ?>">
            <ul class="nav flex-column">
                <?php $isDash = $activeArea === null && $this->request->getParam('controller') === 'Dashboard'; ?>
                <li class="nav-item"><a class="nav-link<?= $isDash ? ' active' : '' ?>"<?= $isDash ? ' aria-current="page"' : '' ?> href="/admin"><?= __('admin.nav.dashboard') ?></a></li>
                <?php foreach ($navAreas as $key => $def): ?>
                    <li class="nav-item mt-2 text-uppercase text-muted small"><?= h(__($def['label'])) ?></li>
                    <?php foreach ($def['items'] as $item): ?>
                        <?php $cur = rtrim($this->request->getRequestTarget(), '/') === $item[1]; ?>
                        <li class="nav-item"><a class="nav-link py-1<?= $cur ? ' active' : '' ?>"<?= $cur ? ' aria-current="page"' : '' ?> href="<?= h($item[1]) ?>"><?= h(__($item[0])) ?></a></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <li class="nav-item mt-3"><a class="nav-link" href="/admin/health"><?= __('admin.nav.health') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/admin/audit"><?= __('admin.nav.audit') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/admin/tokens"><?= __('admin.nav.api_tokens') ?></a></li>
            </ul>
            </nav>
        </aside>
        <main id="main" tabindex="-1" class="col-md-9 col-lg-10 p-4">
            <div aria-live="polite"><?= $this->Flash->render() ?></div>
            <?= $this->fetch('content') ?>
        </main>
    </div>
</div>
</body>
</html>
