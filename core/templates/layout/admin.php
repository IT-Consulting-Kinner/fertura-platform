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
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-3">
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
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link<?= $activeArea === null && $this->request->getParam('controller') === 'Dashboard' ? ' active' : '' ?>" href="/admin"><?= __('admin.nav.dashboard') ?></a></li>
                <?php foreach ($navAreas as $key => $def): ?>
                    <li class="nav-item mt-2 text-uppercase text-muted small"><?= h(__($def['label'])) ?></li>
                    <?php foreach ($def['items'] as $item): ?>
                        <li class="nav-item"><a class="nav-link py-1<?= rtrim($this->request->getRequestTarget(), '/') === $item[1] ? ' active' : '' ?>" href="<?= h($item[1]) ?>"><?= h(__($item[0])) ?></a></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <li class="nav-item mt-3"><a class="nav-link" href="/admin/health"><?= __('admin.nav.health') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/admin/audit"><?= __('admin.nav.audit') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="/admin/tokens"><?= __('admin.nav.api_tokens') ?></a></li>
            </ul>
        </aside>
        <main class="col-md-9 col-lg-10 p-4">
            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </main>
    </div>
</div>
</body>
</html>
