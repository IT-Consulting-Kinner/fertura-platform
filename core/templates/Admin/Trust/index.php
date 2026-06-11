<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $anchors
 * @var list<array<string,mixed>> $revoked
 * @var array{stale:bool,age_days:?int,last_fetch_at:?string,max_age_days:int} $crl
 */
?>
<h1 class="h3 mb-1"><?= h(__('admin.trust.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.trust.intro')) ?></p>

<p>
    <span class="badge text-bg-<?= $crl['stale'] ? 'warning' : 'success' ?>"><?= h(__('admin.trust.crl')) ?></span>
    <span class="ms-1 text-muted small">
        <?= $crl['last_fetch_at'] !== null
            ? h(__('admin.trust.crl_age', (string)($crl['age_days'] ?? '?'), (string)$crl['max_age_days']))
            : h(__('admin.trust.crl_never')) ?>
    </span>
</p>

<h2 class="h5 mt-4"><?= h(__('admin.trust.anchors')) ?></h2>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr>
        <th scope="col">Key-ID</th><th scope="col"><?= h(__('admin.trust.col_type')) ?></th>
        <th scope="col">Publisher</th><th scope="col"><?= h(__('admin.trust.col_validity')) ?></th>
        <th scope="col"><?= h(__('admin.trust.col_status')) ?></th><th scope="col" class="text-end"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($anchors as $a): ?>
        <tr>
            <td class="small text-break font-monospace"><?= h($a['key_id']) ?></td>
            <td class="small"><?= h($a['key_type']) ?></td>
            <td class="small"><?= h((string)($a['publisher'] ?? '—')) ?></td>
            <td class="small"><?= $a['validity']['ok']
                ? '<span class="text-success">' . h(__('admin.trust.valid')) . '</span>'
                : '<span class="text-danger">' . h((string)($a['validity']['reason'] ?? __('admin.trust.invalid'))) . '</span>' ?></td>
            <td>
                <?php if ($a['revoked']): ?><span class="badge text-bg-danger"><?= h(__('admin.trust.revoked')) ?></span>
                <?php elseif ($a['active']): ?><span class="badge text-bg-success"><?= h(__('admin.integrations.active')) ?></span>
                <?php else: ?><span class="badge text-bg-secondary"><?= h(__('admin.integrations.inactive')) ?></span><?php endif; ?>
            </td>
            <td class="text-end">
                <?php if (!$a['revoked']): ?>
                    <?= $this->Form->postLink(__('admin.trust.revoke'), ['action' => 'revoke', $a['key_id']],
                        ['class' => 'btn btn-sm btn-outline-danger', 'confirm' => __('admin.trust.revoke_confirm')]) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($anchors === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.trust.no_anchors')) ?></td></tr><?php endif; ?>
    </tbody>
</table></div>

<details class="mt-2">
    <summary class="btn btn-sm btn-outline-primary"><?= h(__('admin.trust.add')) ?></summary>
    <p class="text-danger small mt-2 mb-1"><?= h(__('admin.trust.add_warning')) ?></p>
    <?= $this->Form->create(null, ['url' => ['action' => 'addAnchor'], 'style' => 'max-width:680px']) ?>
        <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label small mb-0">Key-ID</label>
                <?= $this->Form->control('key_id', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
            <div class="col-md-3"><label class="form-label small mb-0"><?= h(__('admin.trust.col_type')) ?></label>
                <?= $this->Form->select('key_type', ['root' => 'root', 'publisher' => 'publisher'], ['class' => 'form-select form-select-sm']) ?></div>
            <div class="col-md-3"><label class="form-label small mb-0">Publisher</label>
                <?= $this->Form->control('publisher', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
        </div>
        <div class="mb-2"><label class="form-label small mb-0"><?= h(__('admin.trust.public_key')) ?></label>
            <?= $this->Form->control('public_key', ['label' => false, 'type' => 'textarea', 'rows' => 2, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => 'base64 Ed25519 public key']) ?></div>
        <?= $this->Form->button(__('admin.trust.add'), ['class' => 'btn btn-sm btn-primary', 'confirm' => __('admin.trust.add_confirm')]) ?>
    <?= $this->Form->end() ?>
</details>

<h2 class="h5 mt-4"><?= h(__('admin.trust.revoked_list')) ?></h2>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th scope="col">Key-ID</th><th scope="col"><?= h(__('admin.trust.col_reason')) ?></th><th scope="col"><?= h(__('admin.trust.col_source')) ?></th><th scope="col"><?= h(__('admin.trust.col_revoked_at')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($revoked as $r): ?>
        <tr>
            <td class="small text-break font-monospace"><?= h($r['key_id']) ?></td>
            <td class="small"><?= h((string)($r['reason'] ?? '—')) ?></td>
            <td class="small"><?= h($r['source']) ?></td>
            <td class="small"><?= h((string)$r['revoked_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($revoked === []): ?><tr><td colspan="4" class="text-muted"><?= h(__('admin.trust.no_revoked')) ?></td></tr><?php endif; ?>
    </tbody>
</table></div>
