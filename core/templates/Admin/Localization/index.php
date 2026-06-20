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
<h1 class="h3 mb-3"><?= h(__('admin.localization.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.localization.intro')) ?></p>

<?php
$importOptions = [];
foreach ($installed as $ic) {
    $importOptions[$ic['key'] . '|' . $ic['version'] . '|' . $ic['type'] . '|' . $ic['domain']] = $ic['name'] . ' (' . $ic['key'] . ' v' . $ic['version'] . ')';
}
?>
<div class="accordion mb-4" id="langImport">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#importPack" aria-expanded="false" aria-controls="importPack">
                <?= h(__('admin.localization.import_title')) ?>
            </button>
        </h2>
        <div id="importPack" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="alert alert-warning small"><?= h(__('admin.localization.import_unsigned_note')) ?></div>
                <?= $this->Form->create(null, ['url' => ['action' => 'import'], 'type' => 'file']) ?>
                <?= $this->Form->hidden('step', ['value' => 'preview']) ?>
                <div class="row g-3 mw-lg">
                    <div class="col-12">
                        <label class="form-label" for="targetSel"><?= h(__('admin.localization.target_component')) ?></label>
                        <select name="_target" id="targetSel" class="form-select" required>
                            <option value=""><?= h(__('admin.localization.choose')) ?></option>
                            <?php foreach ($importOptions as $val => $label): ?>
                                <option value="<?= h($val) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="importLocale"><?= h(__('admin.localization.locale')) ?></label>
                        <input type="text" name="locale" id="importLocale" class="form-control" placeholder="de_DE" pattern="[a-z]{2}_[A-Z]{2}" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="importFile"><?= h(__('admin.localization.po_file')) ?></label>
                        <input type="file" name="file" id="importFile" accept=".po" class="form-control" required>
                    </div>
                    <?= $this->Form->hidden('component', ['id' => 'fComponent']) ?>
                    <?= $this->Form->hidden('version', ['id' => 'fVersion']) ?>
                    <?= $this->Form->hidden('type', ['id' => 'fType']) ?>
                    <?= $this->Form->hidden('domain', ['id' => 'fDomain']) ?>
                    <div class="col-12">
                        <?= $this->Form->button(__('admin.localization.preview'), ['class' => 'btn btn-primary']) ?>
                    </div>
                </div>
                <?= $this->Form->end() ?>
                <script>
                document.getElementById('targetSel').addEventListener('change', function () {
                    var p = this.value.split('|');
                    document.getElementById('fComponent').value = p[0] || '';
                    document.getElementById('fVersion').value = p[1] || '';
                    document.getElementById('fType').value = p[2] || '';
                    document.getElementById('fDomain').value = p[3] || '';
                });
                </script>
            </div>
        </div>
    </div>
</div>

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
            <table class="table table-sm table-hover align-middle mb-0">
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
                                <?= $this->UiKit->confirmPost(__('admin.localization.delete'), ['action' => 'delete', '?' => $args], __('admin.localization.confirm_delete', $p['locale']), ['class' => 'btn btn-outline-danger btn-sm']) ?>
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
