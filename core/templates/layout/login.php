<?php
/**
 * @var \App\View\AppView $this
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmeldung – Fertura</title>
    <?= $this->Html->css('bootstrap.min') ?>
    <style>body { background:#212529; }</style>
</head>
<body>
<div class="container" style="max-width:420px; margin-top:12vh;">
    <div class="card shadow">
        <div class="card-body p-4">
            <h1 class="h4 mb-3 text-center">Fertura <span class="text-secondary">Admin</span></h1>
            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </div>
    </div>
</div>
</body>
</html>
