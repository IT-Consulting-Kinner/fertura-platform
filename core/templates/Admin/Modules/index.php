<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $modules
 * @var array<int, array<string, mixed>> $deps
 */
$badge = ['active' => 'success', 'installed_inactive' => 'secondary', 'installed' => 'secondary', 'error' => 'danger'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.modules.title')) ?></h1>
    <?= $this->Html->link(__('admin.modules.show_graph'), ['action' => 'graph'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
</div>
<p class="text-muted small"><?= h(__('admin.modules.intro_gui')) ?></p>

<?php $job = $installJob ?? null; ?>
<?php if ($job !== null): ?>
    <div id="installBanner" class="alert alert-info d-flex align-items-center gap-2" data-job-id="<?= h((string)$job['id']) ?>">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
        <span id="installBannerText"><?= h(__('admin.modules.install_running', (string)($job['original_filename'] ?? ''))) ?></span>
    </div>
<?php endif; ?>

<div class="accordion mb-4" id="moduleInstall">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#installModule" aria-expanded="false" aria-controls="installModule">
                <?= h(__('admin.modules.install_heading')) ?>
            </button>
        </h2>
        <div id="installModule" class="accordion-collapse collapse">
            <div class="accordion-body mw-lg">
                <p class="text-muted small mb-2"><?= h(__('admin.modules.install_hint')) ?></p>
                <?= $this->Form->create(null, ['url' => ['action' => 'install'], 'type' => 'file']) ?>
                <div class="mb-3">
                    <label class="form-label" for="packageFile"><?= h(__('admin.modules.package_label')) ?></label>
                    <input type="file" name="package" id="packageFile" accept=".zip" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="isolationSel"><?= h(__('admin.modules.isolation_label')) ?></label>
                    <?= $this->Form->select('isolation', ['in_process' => 'in_process', 'out_of_process' => 'out_of_process'], ['id' => 'isolationSel', 'class' => 'form-select mw-sm']) ?>
                </div>
                <?= $this->Form->button(__('admin.modules.install_submit'), ['class' => 'btn btn-primary', 'disabled' => $job !== null]) ?>
                <?php if ($job !== null): ?><span class="text-muted small ms-2"><?= h(__('admin.modules.install_busy')) ?></span><?php endif; ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.modules.col_key')) ?></th><th scope="col"><?= h(__('admin.modules.col_name')) ?></th><th scope="col"><?= h(__('admin.modules.col_version')) ?></th><th scope="col"><?= h(__('admin.modules.col_type')) ?></th><th scope="col"><?= h(__('admin.modules.col_license')) ?></th><th scope="col"><?= h(__('admin.modules.col_status')) ?></th><th scope="col" class="text-end"><?= h(__('admin.modules.col_action')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($modules as $m): $active = $m['status'] === 'active'; ?>
        <tr>
            <td><code><?= h($m['module_key']) ?></code>
                <?php if (($m['signature_status'] ?? 'valid') === 'revoked'): ?>
                    <span class="badge text-bg-danger" title="<?= h(__('admin.modules.signature_revoked_title')) ?>"><?= h(__('admin.modules.signature_revoked')) ?></span>
                <?php endif; ?></td>
            <td><?= h($m['name']) ?></td>
            <td><?= h($m['version']) ?></td>
            <td><?= h($m['type']) ?></td>
            <td><?= filter_var($m['requires_license'], FILTER_VALIDATE_BOOLEAN) ? '<span class="badge text-bg-info">' . h(__('admin.modules.license_required')) . '</span>' : '–' ?></td>
            <td><span class="badge text-bg-<?= $badge[$m['status']] ?? 'secondary' ?>"><?= h(__('admin.module.status_' . $m['status'])) ?></span></td>
            <td class="text-end">
                <div class="d-inline-flex gap-1">
                <?php if ($active): ?>
                    <?= $this->Form->postLink(__('admin.modules.btn_deactivate'), ['action' => 'deactivate', $m['module_key']], ['class' => 'btn btn-warning btn-sm']) ?>
                <?php else: ?>
                    <?= $this->Form->postLink(__('admin.modules.btn_activate'), ['action' => 'activate', $m['module_key']], ['class' => 'btn btn-success btn-sm']) ?>
                    <?= $this->UiKit->confirmPost(__('admin.modules.btn_delete'), ['action' => 'delete', $m['module_key']], __('admin.modules.confirm_delete', $m['module_key']), ['class' => 'btn btn-outline-danger btn-sm']) ?>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($modules === []): ?><tr><td colspan="7" class="text-muted"><?= h(__('admin.modules.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4"><?= h(__('admin.modules.deps_heading')) ?></h2>
<?php if ($deps === []): ?>
    <p class="text-muted"><?= h(__('admin.modules.deps_empty')) ?></p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($deps as $d): ?>
        <li class="list-group-item">
            <code><?= h($d['module']) ?></code> &rarr; <code><?= h($d['requires']) ?></code>
            <?php if (!empty($d['required_version'])): ?><span class="text-muted small">(<?= h($d['required_version']) ?>)</span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($job !== null): ?>
<script>
(function () {
    var banner = document.getElementById('installBanner');
    if (!banner) { return; }
    var id = banner.getAttribute('data-job-id');
    var txt = document.getElementById('installBannerText');
    var timer = setInterval(function () {
        fetch('/admin/modules/installStatus?id=' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.found) { clearInterval(timer); location.reload(); return; }
                if (d.done) {
                    clearInterval(timer);
                    var sp = banner.querySelector('.spinner-border'); if (sp) { sp.remove(); }
                    banner.classList.remove('alert-info');
                    if (d.status === 'succeeded') {
                        banner.classList.add('alert-success');
                        txt.textContent = <?= json_encode(__('admin.modules.install_done')) ?> + ' ' + (d.module_key || '');
                    } else {
                        banner.classList.add('alert-danger');
                        txt.textContent = <?= json_encode(__('admin.modules.install_failed')) ?> + ' ' + (d.message || '');
                    }
                    setTimeout(function () { location.reload(); }, 3000);
                }
            })
            .catch(function () {});
    }, 2000);
})();
</script>
<?php endif; ?>
