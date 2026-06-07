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

<div class="card mb-4" style="max-width:720px">
    <div class="card-header"><?= h(__('admin.tokens.create_heading')) ?></div>
    <div class="card-body">
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
            <label class="form-label"><?= h(__('admin.tokens.expires')) ?></label>
            <input type="date" name="expires_at" class="form-control" style="max-width:220px">
            <div class="form-text"><?= h(__('admin.tokens.expires_hint')) ?></div>
        </div>
        <?= $this->Form->button(__('admin.tokens.create'), ['class' => 'btn btn-primary']) ?>
        <?= $this->Form->end() ?>
    </div>
</div>

<h2 class="h5"><?= h(__('admin.tokens.list_heading')) ?></h2>
<div class="table-responsive">
<table class="table table-sm align-middle">
    <thead><tr>
        <th><?= h(__('admin.tokens.col_label')) ?></th>
        <th><?= h(__('admin.tokens.col_scopes')) ?></th>
        <th><?= h(__('admin.tokens.col_last_used')) ?></th>
        <th><?= h(__('admin.tokens.col_expires')) ?></th>
        <th><?= h(__('admin.tokens.col_state')) ?></th>
        <th class="text-end"><?= h(__('admin.tokens.col_actions')) ?></th>
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
                    <?= $this->Form->postLink(__('admin.tokens.revoke'), ['action' => 'revoke', $t['id']],
                        ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => __('admin.tokens.confirm_revoke')]) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($tokens === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.tokens.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
</div>
