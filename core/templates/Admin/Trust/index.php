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
                    <?= $this->UiKit->confirmPost(__('admin.trust.revoke'), ['action' => 'revoke', $a['key_id']], __('admin.trust.revoke_confirm'), ['class' => 'btn btn-sm btn-outline-danger']) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($anchors === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.trust.no_anchors')) ?></td></tr><?php endif; ?>
    </tbody>
</table></div>

<div class="accordion mt-3" id="anchorAdd">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#addAnchor" aria-expanded="false" aria-controls="addAnchor">
                <?= h(__('admin.trust.add')) ?>
            </button>
        </h2>
        <div id="addAnchor" class="accordion-collapse collapse">
            <div class="accordion-body mw-lg">
                <p class="text-danger small mb-1"><?= h(__('admin.trust.add_warning')) ?></p>
                <p class="text-muted small mb-1"><?= h(__('admin.trust.publisher_hint')) ?></p>
                <?= $this->Form->create(null, ['url' => ['action' => 'addAnchor']]) ?>
                    <div class="row g-2 mb-2">
                        <div class="col-md-8"><label class="form-label small mb-0" for="trustKeyId">Key-ID</label>
                            <?= $this->Form->control('key_id', ['label' => false, 'id' => 'trustKeyId', 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
                        <div class="col-md-4"><label class="form-label small mb-0" for="trustKeyType"><?= h(__('admin.trust.col_type')) ?></label>
                            <?= $this->Form->select('key_type', ['root' => 'root'], ['id' => 'trustKeyType', 'class' => 'form-select form-select-sm']) ?></div>
                    </div>
                    <div class="mb-2"><label class="form-label small mb-0" for="trustPubKey"><?= h(__('admin.trust.public_key')) ?></label>
                        <?= $this->Form->control('public_key', ['label' => false, 'id' => 'trustPubKey', 'type' => 'textarea', 'rows' => 2, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => 'base64 Ed25519 public key']) ?></div>
                    <?= $this->Form->button(__('admin.trust.add'), ['class' => 'btn btn-sm btn-primary', 'data-confirm' => __('admin.trust.add_confirm'), 'data-confirm-variant' => 'btn-warning']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

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
