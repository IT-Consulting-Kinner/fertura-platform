<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, mixed>|null $session
 * @var array<string, mixed>|null $quiesce
 * @var int $blockingActions
 * @var int $needsAck
 * @var list<array<string, mixed>> $actions
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.maintenance.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.maintenance.intro')) ?></p>

<?php if ($session === null) : ?>
    <div class="card mw-lg">
        <div class="card-body">
            <h2 class="h6"><?= h(__('admin.maintenance.engage_heading')) ?></h2>
            <p class="text-muted small mb-3"><?= h(__('admin.maintenance.engage_hint')) ?></p>
            <?= $this->Form->create(null, ['url' => ['action' => 'engage']]) ?>
            <div class="mb-3">
                <label class="form-label" for="reason"><?= h(__('admin.maintenance.reason_label')) ?></label>
                <input type="text" name="reason" id="reason" class="form-control" maxlength="200">
            </div>
            <?= $this->Form->button(__('admin.maintenance.engage'), [
                'class' => 'btn btn-warning',
                'data-confirm' => __('admin.maintenance.engage_confirm'),
                'data-confirm-variant' => 'btn-warning',
                'escapeTitle' => false,
            ]) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
<?php else : ?>
    <?php $q = $quiesce ?? []; ?>
    <div id="maintBanner" class="alert alert-warning">
        <div class="d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <strong><?= h(__('admin.maintenance.active')) ?></strong>
        </div>
        <div id="maintStatus" class="small mt-2">
            <?= h(__('admin.maintenance.draining', (string)($q['in_flight'] ?? '?'))) ?>
        </div>
    </div>

    <dl class="row small mw-lg">
        <dt class="col-5"><?= h(__('admin.maintenance.reason_label')) ?></dt>
        <dd class="col-7"><?= h((string)($session['reason'] ?? '–')) ?></dd>
        <dt class="col-5"><?= h(__('admin.maintenance.opened_at')) ?></dt>
        <dd class="col-7"><?= h((string)($session['opened_at'] ?? '')) ?></dd>
    </dl>

    <div id="maintBlocking" class="small text-danger mb-2"<?= ($blockingActions ?? 0) > 0 ? '' : ' hidden' ?>>
        <?= h(__('admin.maintenance.blocking_actions', (string)($blockingActions ?? 0))) ?>
    </div>
    <div id="maintNeedsAck" class="small text-danger mb-2"<?= ($needsAck ?? 0) > 0 ? '' : ' hidden' ?>>
        <?= h(__('admin.maintenance.needs_ack', (string)($needsAck ?? 0))) ?>
    </div>

    <?php
    // Manual-restore panel: a needs_manual_restore action holds the maintenance exit
    // ("Flag HALTEN") until the operator restores the pre-action backup out of band
    // (global restore stays CLI-only, decision #7) and acknowledges it here. The panel
    // lists only the UNACKNOWLEDGED ones; acknowledging removes it on the next load.
    $needsRestore = array_filter(
        $actions ?? [],
        static fn(array $a): bool =>
            (string)$a['status'] === 'needs_manual_restore' && ($a['acknowledged_at'] ?? null) === null,
    );
    ?>
    <?php if ($needsRestore !== []) : ?>
        <div class="alert alert-danger mw-lg">
            <h2 class="h6"><?= h(__('admin.maintenance.restore_heading')) ?></h2>
            <p class="small mb-1"><?= h(__('admin.maintenance.restore_intro')) ?></p>
            <p class="small fw-semibold mb-3"><?= h(__('admin.maintenance.restore_warning')) ?></p>
            <?php foreach ($needsRestore as $a) : ?>
                <?php $bid = (string)($a['backup_id'] ?? ''); ?>
                <div class="border rounded bg-white text-body p-2 mb-2">
                    <div class="small mb-1">
                        <code><?= h((string)$a['type']) ?></code> — <?= h((string)($a['message'] ?? '')) ?>
                    </div>
                    <?php if ($bid !== '') : ?>
                        <dl class="row small mb-2">
                            <dt class="col-sm-5"><?= h(__('admin.maintenance.restore_backup_label')) ?></dt>
                            <dd class="col-sm-7"><code><?= h($bid) ?></code></dd>
                            <dt class="col-sm-5"><?= h(__('admin.maintenance.restore_verify_label')) ?></dt>
                            <dd class="col-sm-7"><code>bin/cake backup test-restore <?= h($bid) ?></code></dd>
                            <dt class="col-sm-5"><?= h(__('admin.maintenance.restore_cmd_label')) ?></dt>
                            <dd class="col-sm-7"><code>bin/cake backup restore <?= h($bid) ?> --yes</code></dd>
                        </dl>
                    <?php else : ?>
                        <p class="small text-muted mb-2"><?= h(__('admin.maintenance.restore_no_backup')) ?></p>
                    <?php endif; ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'acknowledgeRestore']]) ?>
                    <?= $this->Form->hidden('action_id', ['value' => (string)$a['id']]) ?>
                    <div class="mb-2">
                        <label class="form-label small mb-0" for="ackNote_<?= h((string)$a['id']) ?>">
                            <?= h(__('admin.maintenance.restore_ack_note_label')) ?>
                        </label>
                        <input type="text" name="note" id="ackNote_<?= h((string)$a['id']) ?>" class="form-control form-control-sm" maxlength="500">
                    </div>
                    <?= $this->Form->button(__('admin.maintenance.restore_ack_submit'), [
                        'class' => 'btn btn-sm btn-danger',
                        'data-confirm' => __('admin.maintenance.restore_ack_confirm'),
                        'data-confirm-variant' => 'btn-danger',
                        'escapeTitle' => false,
                    ]) ?>
                    <?= $this->Form->end() ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="accordion mb-3" id="protectedActions">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#protInstall" aria-expanded="false" aria-controls="protInstall">
                    <?= h(__('admin.maintenance.action_install_heading')) ?>
                </button>
            </h2>
            <div id="protInstall" class="accordion-collapse collapse">
                <div class="accordion-body mw-lg">
                    <p class="text-muted small mb-2"><?= h(__('admin.maintenance.action_install_hint')) ?></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'installModule'], 'type' => 'file']) ?>
                    <div class="mb-3">
                        <label class="form-label" for="protPackage"><?= h(__('admin.modules.package_label')) ?></label>
                        <input type="file" name="package" id="protPackage" accept=".zip" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="protIsolation"><?= h(__('admin.modules.isolation_label')) ?></label>
                        <?= $this->Form->select('isolation', ['in_process' => 'in_process', 'out_of_process' => 'out_of_process'], ['id' => 'protIsolation', 'class' => 'form-select mw-sm']) ?>
                    </div>
                    <?= $this->Form->button(__('admin.maintenance.action_install_submit'), ['class' => 'btn btn-warning']) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#protTenant" aria-expanded="false" aria-controls="protTenant">
                    <?= h(__('admin.maintenance.action_tenant_heading')) ?>
                </button>
            </h2>
            <div id="protTenant" class="accordion-collapse collapse">
                <div class="accordion-body mw-lg">
                    <p class="text-muted small mb-2"><?= h(__('admin.maintenance.action_tenant_hint')) ?></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'provisionTenant']]) ?>
                    <div class="mb-3">
                        <label class="form-label" for="protTenantKey"><?= h(__('admin.maintenance.action_tenant_key')) ?></label>
                        <input type="text" name="key" id="protTenantKey" class="form-control mw-sm" maxlength="63" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="protTenantName"><?= h(__('admin.maintenance.action_tenant_name')) ?></label>
                        <input type="text" name="name" id="protTenantName" class="form-control" maxlength="120" required>
                    </div>
                    <?= $this->Form->button(__('admin.maintenance.action_tenant_submit'), ['class' => 'btn btn-warning']) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#protSecret" aria-expanded="false" aria-controls="protSecret">
                    <?= h(__('admin.maintenance.action_secret_heading')) ?>
                </button>
            </h2>
            <div id="protSecret" class="accordion-collapse collapse">
                <div class="accordion-body mw-lg">
                    <p class="text-muted small mb-2"><?= h(__('admin.maintenance.action_secret_hint')) ?></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'rotateSecret']]) ?>
                    <?= $this->Form->button(__('admin.maintenance.action_secret_submit'), [
                        'class' => 'btn btn-warning',
                        'data-confirm' => __('admin.maintenance.action_secret_confirm'),
                        'data-confirm-variant' => 'btn-warning',
                        'escapeTitle' => false,
                    ]) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#protTrust" aria-expanded="false" aria-controls="protTrust">
                    <?= h(__('admin.maintenance.action_trust_heading')) ?>
                </button>
            </h2>
            <div id="protTrust" class="accordion-collapse collapse">
                <div class="accordion-body mw-lg">
                    <p class="text-muted small mb-2"><?= h(__('admin.maintenance.action_trust_hint')) ?></p>
                    <?= $this->Form->create(null, ['url' => ['action' => 'rotateTrust']]) ?>
                    <div class="mb-3">
                        <label class="form-label" for="protTrustOld"><?= h(__('admin.maintenance.action_trust_old')) ?></label>
                        <input type="text" name="old_key_id" id="protTrustOld" class="form-control mw-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="protTrustNew"><?= h(__('admin.maintenance.action_trust_new')) ?></label>
                        <input type="text" name="new_key_id" id="protTrustNew" class="form-control mw-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="protTrustOverlap"><?= h(__('admin.maintenance.action_trust_overlap')) ?></label>
                        <input type="number" name="overlap_days" id="protTrustOverlap" class="form-control mw-sm" value="30" min="0">
                    </div>
                    <?= $this->Form->button(__('admin.maintenance.action_trust_submit'), ['class' => 'btn btn-warning']) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (($actions ?? []) !== []) : ?>
        <table class="table table-sm table-hover align-middle small mw-lg">
            <thead><tr>
                <th scope="col"><?= h(__('admin.maintenance.action_type')) ?></th>
                <th scope="col"><?= h(__('admin.maintenance.action_status')) ?></th>
                <th scope="col"><?= h(__('admin.maintenance.action_message')) ?></th>
            </tr></thead>
            <tbody>
            <?php
            $variant = [
                'succeeded' => 'success', 'failed' => 'danger', 'needs_manual_restore' => 'danger',
                'aborted' => 'secondary',
            ];
            ?>
            <?php foreach ($actions as $a) : ?>
                <tr>
                    <td><code><?= h((string)$a['type']) ?></code></td>
                    <td>
                        <span class="badge text-bg-<?= h($variant[(string)$a['status']] ?? 'warning') ?>"><?= h((string)$a['status']) ?></span>
                        <?php if ((string)$a['status'] === 'needs_manual_restore' && ($a['acknowledged_at'] ?? null) !== null) : ?>
                            <span class="badge text-bg-secondary"><?= h(__('admin.maintenance.restore_acknowledged_badge')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= h((string)($a['message'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php $canRelease = ($blockingActions ?? 0) === 0 && ($needsAck ?? 0) === 0; ?>
    <div id="maintRelease"<?= $canRelease ? '' : ' hidden' ?>>
        <?= $this->UiKit->confirmPost(
            __('admin.maintenance.release'),
            ['action' => 'release'],
            __('admin.maintenance.release_confirm'),
            ['class' => 'btn btn-danger', 'variant' => 'btn-danger'],
        ) ?>
    </div>

    <script>
    (function () {
        var banner = document.getElementById('maintBanner');
        var status = document.getElementById('maintStatus');
        var blocking = document.getElementById('maintBlocking');
        var needsAck = document.getElementById('maintNeedsAck');
        var release = document.getElementById('maintRelease');
        if (!banner || !status) { return; }
        var tplDraining = <?= json_encode(__('admin.maintenance.draining', '%N%')) ?>;
        var tplDrained = <?= json_encode(__('admin.maintenance.drained')) ?>;
        var tplTimedOut = <?= json_encode(__('admin.maintenance.timed_out')) ?>;
        var tplBlocking = <?= json_encode(__('admin.maintenance.blocking_actions', '%N%')) ?>;
        var tplNeedsAck = <?= json_encode(__('admin.maintenance.needs_ack', '%N%')) ?>;
        // A fresh needs_manual_restore (e.g. a crash swept mid-poll) appears server-
        // rendered on the next load; reload once when one shows up so its restore
        // panel + acknowledge form are present, not just the count.
        var ackSeen = <?= ($needsAck ?? 0) > 0 ? 'true' : 'false' ?>;
        var timer = setInterval(function () {
            fetch('/admin/maintenance/status', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.active) { clearInterval(timer); location.reload(); return; }
                    if (blocking) {
                        if (d.blocking_actions > 0) {
                            blocking.hidden = false;
                            blocking.textContent = tplBlocking.replace('%N%', d.blocking_actions);
                        } else {
                            blocking.hidden = true;
                        }
                    }
                    if (needsAck) {
                        if (d.needs_ack > 0) {
                            needsAck.hidden = false;
                            needsAck.textContent = tplNeedsAck.replace('%N%', d.needs_ack);
                            // A restore that wasn't server-rendered yet (e.g. swept by a
                            // recovery sweep between loads) -> reload so its acknowledge
                            // form appears, not just the count.
                            if (!ackSeen) { clearInterval(timer); location.reload(); return; }
                        } else {
                            needsAck.hidden = true;
                        }
                    }
                    // Mirror the server gate: only offer release when it would succeed
                    // (nothing in flight AND no unacknowledged restore). The server stays
                    // authoritative; this just avoids inviting a refused click.
                    if (release) { release.hidden = !d.can_release; }
                    if (d.done) {
                        clearInterval(timer);
                        var sp = banner.querySelector('.spinner-border'); if (sp) { sp.remove(); }
                        banner.classList.remove('alert-warning');
                        banner.classList.add(d.timed_out ? 'alert-danger' : 'alert-success');
                        status.textContent = d.timed_out ? tplTimedOut : tplDrained;
                    } else {
                        status.textContent = tplDraining.replace('%N%', d.in_flight);
                    }
                })
                .catch(function () {});
        }, 2000);
    })();
    </script>
<?php endif; ?>
