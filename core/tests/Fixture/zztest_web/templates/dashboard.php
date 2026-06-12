<?php
/**
 * @var \App\View\AppView $this
 * @var string $greeting
 * @var ?string $userId
 * @var string $echoedPath
 * @var ?string $echoedId
 */
?>
<h1><?= h($greeting) ?></h1>
<p data-test="user"><?= h((string)$userId) ?></p>
<p data-test="path"><?= h($echoedPath) ?></p>
<p data-test="id"><?= h((string)$echoedId) ?></p>
