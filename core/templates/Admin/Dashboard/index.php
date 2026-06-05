<?php
/**
 * @var \App\View\AppView $this
 * @var array<string,int> $stats
 * @var array<int,array<string,mixed>> $modulesByStatus
 */
$this->assign('title', 'Dashboard');
$cards = [
    ['Aktive Benutzer', $stats['users_active']],
    ['Aktive Gruppen', $stats['groups_active']],
    ['Aktive Module', $stats['modules_active'] . ' / ' . $stats['modules_total']],
    ['Aktive Contracts', $stats['contracts']],
    ['Outbox offen', $stats['outbox_pending']],
    ['Dead-Letter', $stats['outbox_deadletter']],
    ['Lizenzen', $stats['licenses']],
];
?>
<h1 class="h3 mb-4">Dashboard</h1>
<div class="row g-3">
    <?php foreach ($cards as $card): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card<?= $card[0] === 'Dead-Letter' && (int)$card[1] > 0 ? ' border-danger' : '' ?>">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?= h($card[0]) ?></div>
                    <div class="display-6"><?= h((string)$card[1]) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<h2 class="h5 mt-4">Module nach Status</h2>
<table class="table table-sm w-auto">
    <?php foreach ($modulesByStatus as $row): ?>
        <tr><td class="pe-4"><?= h($row['status']) ?></td><td><?= h((string)$row['c']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$modulesByStatus): ?><tr><td class="text-muted">keine Module</td></tr><?php endif; ?>
</table>
