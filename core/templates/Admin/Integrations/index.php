<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $webhooks
 * @var list<array<string,mixed>> $deliveries
 * @var list<array<string,mixed>> $ssoProviders
 * @var list<array<string,mixed>> $automationRules
 * @var list<array<string,mixed>> $workflows
 */
$badge = static fn (bool $a): string => $a
    ? '<span class="badge text-bg-success">' . h(__('admin.integrations.active')) . '</span>'
    : '<span class="badge text-bg-secondary">' . h(__('admin.integrations.inactive')) . '</span>';
?>
<h1 class="h3 mb-1"><?= h(__('admin.integrations.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.integrations.intro')) ?></p>

<h2 class="h5 mt-4"><?= h(__('admin.integrations.webhooks')) ?></h2>
<?php if ($webhooks === []): ?>
    <p class="text-muted"><?= h(__('admin.integrations.none')) ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Name</th><th scope="col">URL</th><th scope="col">Events</th><th scope="col"><?= h(__('admin.integrations.status')) ?></th><th scope="col" class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($webhooks as $w): ?>
        <tr>
            <td><?= h($w['name']) ?></td>
            <td class="small text-break"><?= h($w['url']) ?></td>
            <td class="small"><?= h($w['event_filter']) ?></td>
            <td><?= $badge((bool)$w['active']) ?></td>
            <td class="text-end">
                <?= $this->Form->postLink(__('admin.integrations.toggle'), ['action' => 'webhookToggle', $w['id']], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <?= $this->Form->postLink(__('admin.integrations.delete'), ['action' => 'webhookDelete', $w['id']], ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('admin.integrations.confirm_delete')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h3 class="h6 mt-3"><?= h(__('admin.integrations.deliveries')) ?></h3>
<?php if ($deliveries === []): ?>
    <p class="text-muted"><?= h(__('admin.integrations.none')) ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Event</th><th scope="col"><?= h(__('admin.integrations.status')) ?></th><th scope="col">HTTP</th><th scope="col">Versuche</th><th scope="col" class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d): ?>
        <tr>
            <td class="small"><?= h($d['event_name']) ?></td>
            <td><span class="badge text-bg-<?= $d['status'] === 'done' ? 'success' : ($d['status'] === 'dead_letter' ? 'danger' : 'light border') ?>"><?= h($d['status']) ?></span></td>
            <td class="small"><?= h((string)($d['last_status_code'] ?? '-')) ?></td>
            <td class="small"><?= (int)$d['attempt_count'] ?></td>
            <td class="text-end">
                <?php if (in_array($d['status'], ['dead_letter', 'pending'], true)): ?>
                    <?= $this->Form->postLink(__('admin.integrations.retry'), ['action' => 'deliveryRetry', $d['id']], ['class' => 'btn btn-sm btn-outline-warning']) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 class="h5 mt-4"><?= h(__('admin.integrations.sso')) ?></h2>
<?php if ($ssoProviders === []): ?>
    <p class="text-muted"><?= h(__('admin.integrations.none')) ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Name</th><th scope="col">Typ</th><th scope="col"><?= h(__('admin.integrations.status')) ?></th><th scope="col" class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($ssoProviders as $p): ?>
        <tr>
            <td><?= h($p['name']) ?></td>
            <td class="small"><?= h($p['type']) ?></td>
            <td><?= $badge((bool)$p['active']) ?></td>
            <td class="text-end">
                <?= $this->Form->postLink(__('admin.integrations.toggle'), ['action' => 'ssoToggle', $p['id']], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <?= $this->Form->postLink(__('admin.integrations.delete'), ['action' => 'ssoDelete', $p['id']], ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('admin.integrations.confirm_delete')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 class="h5 mt-4"><?= h(__('admin.integrations.automation')) ?></h2>
<?php if ($automationRules === []): ?>
    <p class="text-muted"><?= h(__('admin.integrations.none')) ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Name</th><th scope="col">Event</th><th scope="col"><?= h(__('admin.integrations.status')) ?></th><th scope="col" class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($automationRules as $r): ?>
        <tr>
            <td><?= h($r['name']) ?></td>
            <td class="small"><?= h($r['event']) ?></td>
            <td><?= $badge((bool)$r['active']) ?></td>
            <td class="text-end">
                <?= $this->Form->postLink(__('admin.integrations.toggle'), ['action' => 'automationToggle', $r['id']], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <?= $this->Form->postLink(__('admin.integrations.delete'), ['action' => 'automationDelete', $r['id']], ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('admin.integrations.confirm_delete')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<h2 class="h5 mt-4"><?= h(__('admin.integrations.workflows')) ?></h2>
<?php if ($workflows === []): ?>
    <p class="text-muted"><?= h(__('admin.integrations.none')) ?></p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Name</th><th scope="col">Entity</th><th scope="col">Start</th><th scope="col"><?= h(__('admin.integrations.status')) ?></th><th scope="col" class="text-end"></th></tr></thead>
    <tbody>
    <?php foreach ($workflows as $w): ?>
        <tr>
            <td><?= h($w['name']) ?></td>
            <td class="small"><?= h($w['entity_type']) ?></td>
            <td class="small"><?= h($w['initial_state']) ?></td>
            <td><?= $badge((bool)$w['active']) ?></td>
            <td class="text-end">
                <?= $this->Form->postLink(__('admin.integrations.toggle'), ['action' => 'workflowToggle', $w['id']], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <?= $this->Form->postLink(__('admin.integrations.delete'), ['action' => 'workflowDelete', $w['id']], ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('admin.integrations.confirm_delete')]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<p class="text-muted small mt-4"><?= h(__('admin.integrations.cli_hint')) ?></p>
