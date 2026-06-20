<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $tokens
 * @var list<string> $knownScopes
 * @var string|null $newToken
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.tokens.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.tokens.intro')) ?></p>

<?php if ($newToken !== null): ?>
    <div class="alert alert-success">
        <strong><?= h(__('admin.tokens.new_heading')) ?></strong>
        <div class="small"><?= h(__('admin.tokens.new_hint')) ?></div>
        <code class="user-select-all d-block mt-2 p-2 bg-light border"><?= h($newToken) ?></code>
    </div>
<?php endif; ?>

<?php $open = !empty($openCreate); ?>
<div class="accordion mb-4" id="tokenCreate">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button<?= $open ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#createToken" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="createToken">
                <?= h(__('admin.tokens.create_heading')) ?>
            </button>
        </h2>
        <div id="createToken" class="accordion-collapse collapse<?= $open ? ' show' : '' ?>">
            <div class="accordion-body mw-lg">
                <?= $this->Form->create(null, ['url' => ['action' => 'create']]) ?>
                <div class="mb-3">
                    <label class="form-label"><?= h(__('admin.tokens.label')) ?></label>
                    <?= $this->Form->control('label', ['label' => false, 'class' => 'form-control', 'placeholder' => 'CI deploy bot']) ?>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= h(__('admin.tokens.scopes')) ?></label>
                    <?php foreach ($knownScopes as $s): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="scopes[]" value="<?= h($s) ?>" id="sc_<?= h($s) ?>">
                            <label class="form-check-label" for="sc_<?= h($s) ?>"><code><?= h($s) ?></code></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="tokenExpires"><?= h(__('admin.tokens.expires')) ?></label>
                    <input type="date" name="expires_at" id="tokenExpires" class="form-control mw-sm">
                    <div class="form-text"><?= h(__('admin.tokens.expires_hint')) ?></div>
                </div>
                <?= $this->Form->button(__('admin.tokens.create'), ['class' => 'btn btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<h2 class="h5"><?= h(__('admin.tokens.list_heading')) ?></h2>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.tokens.col_label')) ?></th>
        <th scope="col"><?= h(__('admin.tokens.col_scopes')) ?></th>
        <th scope="col"><?= h(__('admin.tokens.col_last_used')) ?></th>
        <th scope="col"><?= h(__('admin.tokens.col_expires')) ?></th>
        <th scope="col"><?= h(__('admin.tokens.col_state')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.tokens.col_actions')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tokens as $t): $revoked = $t['revoked_at'] !== null; ?>
        <tr class="<?= $revoked ? 'text-muted' : '' ?>">
            <td><?= h((string)($t['label'] ?? '–')) ?></td>
            <td><?php foreach ((array)$t['scopes'] as $s): ?><span class="badge text-bg-light border"><?= h($s) ?></span> <?php endforeach; ?></td>
            <td class="small"><?= h((string)($t['last_used_at'] ?? '–')) ?></td>
            <td class="small"><?= h((string)($t['expires_at'] ?? '–')) ?></td>
            <td>
                <?php if ($revoked): ?>
                    <span class="badge text-bg-secondary"><?= h(__('admin.tokens.revoked')) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-success"><?= h(__('admin.tokens.active')) ?></span>
                <?php endif; ?>
            </td>
            <td class="text-end">
                <?php if (!$revoked): ?>
                    <?= $this->UiKit->confirmPost(__('admin.tokens.revoke'), ['action' => 'revoke', $t['id']], __('admin.tokens.confirm_revoke'), ['class' => 'btn btn-outline-danger btn-sm']) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tokens === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.tokens.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
</div>
