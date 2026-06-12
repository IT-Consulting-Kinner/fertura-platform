<?php
/**
 * @var \App\View\AppView $this
 * @var string $heading
 * @var ?string $userId
 */
?>
<h1><?= h($heading) ?></h1>
<p data-test="user"><?= h((string)$userId) ?></p>
