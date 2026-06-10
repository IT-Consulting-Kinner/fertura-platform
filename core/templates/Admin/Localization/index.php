<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $components
 * @var list<array<string,mixed>> $installed
 */
$badge = static function (string $status): string {
    return match ($status) {
        'clean' => 'text-bg-success',
        'notice' => 'text-bg-warning',
        'error' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.localization.title')) ?></h1>
    <?= $this->Html->link(__('admin.localization.import'), ['action' => 'import'], ['class' => 'btn btn-primary btn-sm']) ?>
</div>
<p class="text-muted small"><?= h(__('admin.localization.intro')) ?></p>

<?php if ($components === []): ?>
    <div class="alert alert-info"><?= h(__('admin.localization.empty')) ?></div>
<?php endif; ?>

<?php foreach ($components as $c): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong><?= h($c['name']) ?></strong>
                <code class="small"><?= h($c['key']) ?></code>
                <span class="text-muted small"><?= h($c['type']) ?> · v<?= h((string)$c['active_version']) ?></span>
                <?php if ($c['active']): ?>
                    <span class="badge text-bg-success"><?= h(__('admin.localization.active')) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-secondary"><?= h(__('admin.localization.inactive')) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($c['shipped'])): ?>
                <div class="px-3 pt-2 small text-muted">
                    <?= h(__('admin.localization.shipped')) ?>:
                    <?php foreach ($c['shipped'] as $l): ?><span class="badge text-bg-light border"><?= h($l) ?></span> <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col"><?= h(__('admin.localization.col_locale')) ?></th>
                        <th scope="col"><?= h(__('admin.localization.col_version')) ?></th>
                        <th scope="col"><?= h(__('admin.localization.col_status')) ?></th>
                        <th scope="col"><?= h(__('admin.localization.col_flags')) ?></th>
                        <th scope="col" class="text-end"><?= h(__('admin.localization.col_actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($c['packs'] as $p): ?>
                    <tr>
                        <td><code><?= h($p['locale']) ?></code></td>
                        <td class="small text-muted">v<?= h($p['version']) ?></td>
                        <td><span class="badge <?= $badge($p['status']) ?>"><?= h(__('admin.localization.status_' . $p['status'])) ?></span></td>
                        <td class="small">
                            <?php if ($p['signed']): ?><span class="badge text-bg-dark"><?= h(__('admin.localization.signed')) ?></span><?php endif; ?>
                            <?php if ($p['reviewed']): ?><span class="badge text-bg-info"><?= h(__('admin.localization.reviewed')) ?></span><?php else: ?><span class="badge text-bg-warning"><?= h(__('admin.localization.unreviewed')) ?></span><?php endif; ?>
                            <?php if ($p['edited']): ?><span class="badge text-bg-light border"><?= h(__('admin.localization.edited')) ?></span><?php endif; ?>
                            <span class="text-muted"><?= h($p['source']) ?></span>
                        </td>
                        <td class="text-end">
                            <?php $args = ['component' => $c['key'], 'version' => $p['version'], 'locale' => $p['locale'], 'domain' => $p['domain']]; ?>
                            <?= $this->Html->link(__('admin.localization.edit'), ['action' => 'edit', '?' => $args], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                            <?php if (!$p['reviewed']): ?>
                                <?= $this->Form->postLink(__('admin.localization.review'), ['action' => 'review', '?' => $args], ['class' => 'btn btn-outline-info btn-sm']) ?>
                            <?php endif; ?>
                            <?php if ($p['deletable']): ?>
                                <?= $this->Form->postLink(__('admin.localization.delete'), ['action' => 'delete', '?' => $args],
                                    ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => __('admin.localization.confirm_delete', $p['locale'])]) ?>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary btn-sm" disabled title="<?= h(__('admin.localization.delete_locked')) ?>"><?= h(__('admin.localization.delete')) ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
