<?php
/**
 * Admin shell: top menu (Dashboard + Module/Administration dropdowns), language
 * select and a user menu. Dropdowns use native <details>/<summary> (no JS
 * framework is loaded); the language select auto-submits via a tiny inline
 * handler.
 *
 * @var \App\View\AppView $this
 */
$currentUser = $currentUser ?? null;
$activeTop = $activeTop ?? '';
$topMenu = $topMenu ?? ['module' => [], 'administration' => []];

/** Target of a group entry: its single page directly, else the tile drill-down. */
$groupUrl = static function (string $key, array $def): string {
    $items = $def['items'];

    return count($items) === 1 ? (string)$items[0][1] : '/admin/section/' . $key;
};

/** Renders one top-menu dropdown from a set of nav groups. */
$dropdown = function (string $top, string $label, array $groups) use ($activeTop, $groupUrl): string {
    if ($groups === []) {
        return '';
    }
    $links = '';
    foreach ($groups as $key => $def) {
        $links .= sprintf(
            '<a class="dd-item" href="%s">%s</a>',
            h($groupUrl($key, $def)),
            h(__($def['label'])),
        );
    }

    return sprintf(
        '<details class="nav-dd"%s><summary class="nav-link%s">%s <span class="caret">&#9662;</span></summary><div class="dd-menu">%s</div></details>',
        $activeTop === $top ? ' open' : '',
        $activeTop === $top ? ' active' : '',
        h($label),
        $links,
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
        a:focus-visible, button:focus-visible, summary:focus-visible, .form-select:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
        /* Doppelter Abstand zwischen Marke und erstem Menüpunkt. */
        .navbar-brand { margin-right: 3rem; }
        .top-nav { display:flex; align-items:center; gap:.25rem; }
        .top-nav .nav-link { color:rgba(255,255,255,.85); padding:.5rem .75rem; border-radius:.375rem; text-decoration:none; }
        .top-nav .nav-link:hover { color:#fff; }
        .top-nav .nav-link.active { color:#fff; font-weight:600; background:rgba(255,255,255,.12); }
        /* Native <details> dropdown (kein JS-Framework geladen). */
        .nav-dd { position:relative; }
        .nav-dd > summary { list-style:none; cursor:pointer; user-select:none; }
        .nav-dd > summary::-webkit-details-marker { display:none; }
        .nav-dd .caret { font-size:.7em; opacity:.8; }
        .nav-dd .dd-menu { position:absolute; top:calc(100% + .25rem); left:0; min-width:15rem; background:#fff; border:1px solid rgba(0,0,0,.15); border-radius:.5rem; box-shadow:0 .5rem 1.25rem rgba(0,0,0,.18); padding:.25rem 0; z-index:1050; }
        .nav-dd .dd-menu.dd-right { left:auto; right:0; }
        .nav-dd .dd-item { display:block; padding:.45rem 1rem; color:#212529; text-decoration:none; white-space:nowrap; }
        .nav-dd .dd-item:hover { background:#f1f3f5; }
        .locale-select { max-width:11rem; }
        a.card:hover { border-color:#0d6efd; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<nav class="navbar navbar-dark bg-dark px-3" aria-label="<?= h(__('a11y.nav_main')) ?>">
    <span class="navbar-brand mb-0">Fertura <span class="text-secondary">Admin</span></span>
    <div class="top-nav me-auto">
        <a class="nav-link<?= $activeTop === 'dashboard' ? ' active' : '' ?>"<?= $activeTop === 'dashboard' ? ' aria-current="page"' : '' ?> href="/admin"><?= h(__('admin.nav.dashboard')) ?></a>
        <?= $dropdown('module', (string)__('admin.nav.modules'), $topMenu['module']) ?>
        <?= $dropdown('administration', (string)__('admin.nav.administration'), $topMenu['administration']) ?>
    </div>
    <div class="d-flex align-items-center text-light gap-2">
        <?= $this->cell('LocaleSwitcher', [true, 'select']) ?>
        <?php if ($currentUser !== null): ?>
            <details class="nav-dd">
                <summary class="btn btn-sm btn-outline-light">
                    <?= h(__('admin.nav.user_prefix')) ?><?= h($currentUser->get('username')) ?> <span class="caret">&#9662;</span>
                </summary>
                <div class="dd-menu dd-right">
                    <a class="dd-item" href="/account"><?= h(__('admin.nav.profile')) ?></a>
                    <a class="dd-item" href="/logout"><?= h(__('admin.nav.logout')) ?></a>
                </div>
            </details>
        <?php endif; ?>
    </div>
</nav>
<main id="main" tabindex="-1" class="container-fluid p-4">
    <div aria-live="polite"><?= $this->Flash->render() ?></div>
    <?= $this->fetch('content') ?>
</main>
</body>
</html>
