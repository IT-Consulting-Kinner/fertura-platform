<?php
/**
 * Admin shell: top menu (Dashboard + Module/Administration dropdowns), language
 * select and a user menu. Dropdowns are Bootstrap 5 dropdowns (the bundle's JS
 * closes them on an outside click and handles keyboard/ARIA); the language select
 * auto-submits via a tiny inline handler.
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
            '<li><a class="dropdown-item" href="%s">%s</a></li>',
            h($groupUrl($key, $def)),
            h(__($def['label'])),
        );
    }

    // Bootstrap dropdown: the bundle's JS handles open/close (incl. closing on an
    // outside click), keyboard and ARIA. Only the active section is highlighted;
    // aria-current announces the current location (the active sub-page lives on
    // the section/tile page, not the bar).
    return sprintf(
        '<div class="nav-item dropdown">'
        . '<a class="nav-link dropdown-toggle%s" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"%s>%s</a>'
        . '<ul class="dropdown-menu">%s</ul></div>',
        $activeTop === $top ? ' active' : '',
        $activeTop === $top ? ' aria-current="page"' : '',
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
        .top-nav .nav-link { color:rgba(255,255,255,.85); padding:.5rem .75rem; border-radius:.375rem; text-decoration:none; cursor:pointer; }
        .top-nav .nav-link:hover { color:#fff; }
        .top-nav .nav-link.active { color:#fff; font-weight:600; background:rgba(255,255,255,.12); }
        /* Top-menu dropdowns are Bootstrap 5 dropdowns; just widen the menu a bit. */
        .top-nav .dropdown-menu { min-width:15rem; }
        .locale-select { max-width:11rem; }
        a.card:hover { border-color:#0d6efd; }
        /* Responsive tile grid: a min tile width drives a dynamic column count
           (auto-fill adds columns on wider screens instead of over-wide tiles). */
        .tile-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(16rem, 1fr)); gap:1rem; }
        /* Form-width utilities (replace scattered inline max-width styles). */
        .mw-sm { max-width:28rem; } .mw-md { max-width:36rem; } .mw-lg { max-width:44rem; }
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
            <div class="nav-item dropdown">
                <a class="btn btn-sm btn-outline-light dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?= h(__('admin.nav.user_prefix')) ?><?= h($currentUser->get('username')) ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="/account"><?= h(__('admin.nav.profile')) ?></a></li>
                    <li><a class="dropdown-item" href="/logout"><?= h(__('admin.nav.logout')) ?></a></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main id="main" tabindex="-1" class="container-fluid p-4">
    <?= $this->element('admin_breadcrumb', ['crumbs' => $breadcrumb ?? []]) ?>
    <div aria-live="polite"><?= $this->Flash->render() ?></div>
    <?= $this->fetch('content') ?>
</main>
<?= $this->element('admin_confirm_modal') ?>
<?= $this->Html->script('bootstrap.bundle.min') ?>
<?= $this->Html->script('admin') ?>
</body>
</html>
