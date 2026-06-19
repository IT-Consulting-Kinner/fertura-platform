<?php
/**
 * @var \App\View\AppView $this
 * @var array $params
 * @var string $message
 */
$class = 'alert ' . (!empty($params['class']) ? (string)$params['class'] : 'alert-secondary');
if (!isset($params['escape']) || $params['escape'] !== false) {
    $message = h($message);
}
?>
<div class="<?= h($class) ?>" role="alert"><?= $message ?></div>
