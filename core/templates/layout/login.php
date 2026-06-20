<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', \Cake\I18n\I18n::getLocale())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= __('auth.layout.title') ?></title>
    <?= $this->Html->css('bootstrap.min') ?>
    <style>
        body { background:#212529; }
        a:focus-visible, button:focus-visible, .form-control:focus-visible { outline: 3px solid #0d6efd; outline-offset: 2px; }
        .skip-link { position:absolute; left:-999px; top:0; z-index:1080; background:#0d6efd; color:#fff; padding:.5rem 1rem; }
        .skip-link:focus { left:.5rem; top:.5rem; }
        /* Flash messages: the login layout loads only Bootstrap (not cake.css),
           so style the flash inline as Bootstrap-style alerts — coloured AND
           spaced (margin-bottom) so the error no longer sticks to the form. */
        .message { padding:.5rem 1rem; border:1px solid transparent; border-radius:.375rem; margin-bottom:1rem; }
        .message.error { color:#842029; background:#f8d7da; border-color:#f5c2c7; }
        .message.success { color:#0f5132; background:#d1e7dd; border-color:#badbcc; }
        .message.warning { color:#664d03; background:#fff3cd; border-color:#ffecb5; }
        .message.info { color:#055160; background:#cff4fc; border-color:#b6effb; }
        .message.hidden { display:none; }
    </style>
</head>
<body>
<a class="skip-link" href="#main"><?= __('a11y.skip_to_content') ?></a>
<main id="main" class="container" style="max-width:420px; margin-top:12vh;">
    <div class="d-flex justify-content-end mb-2"><?= $this->cell('LocaleSwitcher', [false, 'select']) ?></div>
    <div class="card shadow">
        <div class="card-body p-4">
            <h1 class="h4 mb-3 text-center">Fertura <span class="text-secondary">Admin</span></h1>
            <div aria-live="polite"><?= $this->Flash->render() ?></div>
            <?= $this->fetch('content') ?>
        </div>
    </div>
</main>
</body>
</html>
