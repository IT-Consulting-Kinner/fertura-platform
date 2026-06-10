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
    </style>
</head>
<body>
<main id="main" class="container" style="max-width:420px; margin-top:12vh;">
    <div class="d-flex justify-content-end mb-2"><?= $this->cell('LocaleSwitcher', [false]) ?></div>
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
