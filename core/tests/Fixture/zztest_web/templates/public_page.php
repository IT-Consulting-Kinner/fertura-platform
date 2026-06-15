<?php
/**
 * @var \App\View\AppView $this
 * @var string $greeting
 * @var ?string $userId
 * @var string $clientIp
 */
?>
<h1><?= h($greeting) ?></h1>
<p data-test="user"><?= h((string)$userId) ?></p>
<p data-test="ip"><?= h($clientIp) ?></p>
