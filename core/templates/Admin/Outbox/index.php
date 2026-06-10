<?php
/**
 * @var \App\View\AppView $this
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $deadLetters
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.outbox.title')) ?></h1>
    <?php if (($counts['dead_letter'] ?? 0) > 0): ?>
        <?= $this->Form->postLink(
            __('admin.outbox.retry_all'),
            ['action' => 'retryAll'],
            ['class' => 'btn btn-warning btn-sm', 'confirm' => __('admin.outbox.confirm_retry_all', $counts['dead_letter'])],
        ) ?>
    <?php endif; ?>
</div>
<p class="text-muted small"><?= h(__('admin.outbox.intro')) ?></p>

<div class="d-flex gap-2 mb-4 flex-wrap">
    <?php foreach (['pending', 'processing', 'done', 'dead_letter', 'discarded'] as $st):
        $cls = $st === 'dead_letter' ? 'text-bg-danger' : ($st === 'discarded' ? 'text-bg-secondary' : ($st === 'done' ? 'text-bg-success' : 'text-bg-light border'));
    ?>
        <span class="badge <?= $cls ?> fs-6"><?= h(__('admin.outbox.status_' . $st)) ?>: <?= (int)($counts[$st] ?? 0) ?></span>
    <?php endforeach; ?>
</div>

<h2 class="h5"><?= h(__('admin.outbox.deadletter_heading')) ?></h2>
<?php if ($deadLetters === []): ?>
    <div class="alert alert-success"><?= h(__('admin.outbox.deadletter_empty')) ?></div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.outbox.col_created')) ?></th>
        <th scope="col"><?= h(__('admin.outbox.col_contract')) ?></th>
        <th scope="col"><?= h(__('admin.outbox.col_attempts')) ?></th>
        <th scope="col"><?= h(__('admin.outbox.col_error')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.outbox.col_actions')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($deadLetters as $e): ?>
        <tr>
            <td class="small text-muted"><?= h((string)$e['created_at']) ?></td>
            <td><code><?= h($e['contract_name']) ?></code>
                <?php if (!empty($e['correlation_id'])): ?><div class="small text-muted">corr: <?= h((string)$e['correlation_id']) ?></div><?php endif; ?>
            </td>
            <td><?= (int)$e['attempt_count'] ?>/<?= (int)$e['max_attempts'] ?></td>
            <td class="small text-danger" style="max-width:420px"><?= h((string)($e['last_error'] ?? '')) ?></td>
            <td class="text-end text-nowrap">
                <?= $this->Form->postLink(__('admin.outbox.retry'), ['action' => 'retry', $e['id']], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                <?= $this->Form->postLink(__('admin.outbox.discard'), ['action' => 'discard', $e['id']],
                    ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => __('admin.outbox.confirm_discard')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
