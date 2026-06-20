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
                <?= $this->UiKit->confirmPost(__('admin.integrations.delete'), ['action' => 'webhookDelete', $w['id']], __('admin.integrations.confirm_delete'), ['class' => 'btn btn-sm btn-outline-danger']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="accordion mt-2" id="webhookAdd">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#webhookForm" aria-expanded="false" aria-controls="webhookForm"><?= h(__('admin.integrations.webhook_add')) ?></button>
        </h2>
        <div id="webhookForm" class="accordion-collapse collapse">
            <div class="accordion-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'webhookCreate'], 'class' => 'row g-2 align-items-end']) ?>
                    <div class="col-md-3"><label class="form-label small mb-0">Name</label>
                        <?= $this->Form->control('name', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
                    <div class="col-md-4"><label class="form-label small mb-0">URL (https)</label>
                        <?= $this->Form->control('url', ['label' => false, 'required' => true, 'type' => 'url', 'class' => 'form-control form-control-sm', 'placeholder' => 'https://…']) ?></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?= h(__('admin.integrations.event_filter')) ?></label>
                        <?= $this->Form->control('event_filter', ['label' => false, 'class' => 'form-control form-control-sm', 'placeholder' => '*']) ?></div>
                    <div class="col-md-2"><label class="form-label small mb-0"><?= h(__('admin.integrations.hmac_secret')) ?></label>
                        <?= $this->Form->control('secret', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
                    <div class="col-md-1"><?= $this->Form->button(__('admin.integrations.add'), ['class' => 'btn btn-sm btn-primary']) ?></div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

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
                <?= $this->UiKit->confirmPost(__('admin.integrations.delete'), ['action' => 'ssoDelete', $p['id']], __('admin.integrations.confirm_delete'), ['class' => 'btn btn-sm btn-outline-danger']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="accordion mt-2" id="ssoAdd">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ssoForm" aria-expanded="false" aria-controls="ssoForm"><?= h(__('admin.integrations.sso_add')) ?></button>
        </h2>
        <div id="ssoForm" class="accordion-collapse collapse">
            <div class="accordion-body">
    <?= $this->Form->create(null, ['url' => ['action' => 'ssoCreate']]) ?>
        <div class="row g-2 mb-2">
            <div class="col-md-3"><label class="form-label small mb-0"><?= h(__('admin.integrations.sso_type')) ?></label>
                <?= $this->Form->select('type', ['oidc' => 'OIDC', 'saml' => 'SAML'], ['id' => 'sso-type', 'class' => 'form-select form-select-sm']) ?></div>
            <div class="col-md-5"><label class="form-label small mb-0">Name</label>
                <?= $this->Form->control('name', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
            <div class="col-md-4"><label class="form-label small mb-0"><?= h(__('admin.integrations.sso_button_label')) ?></label>
                <?= $this->Form->control('button_label', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
        </div>
        <div id="sso-oidc" class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label small mb-0">Issuer-URL</label>
                <?= $this->Form->control('issuer', ['label' => false, 'class' => 'form-control form-control-sm', 'placeholder' => 'https://idp.example/']) ?></div>
            <div class="col-md-6"><label class="form-label small mb-0">Client-ID</label>
                <?= $this->Form->control('client_id', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
            <div class="col-md-6"><label class="form-label small mb-0">Client-Secret</label>
                <?= $this->Form->control('client_secret', ['label' => false, 'type' => 'password', 'class' => 'form-control form-control-sm', 'autocomplete' => 'off']) ?></div>
            <div class="col-md-6"><label class="form-label small mb-0">Scopes</label>
                <?= $this->Form->control('scopes', ['label' => false, 'class' => 'form-control form-control-sm', 'placeholder' => 'openid email profile']) ?></div>
        </div>
        <div id="sso-saml" class="row g-2 mb-2" hidden>
            <div class="col-md-6"><label class="form-label small mb-0">IdP Entity-ID</label>
                <?= $this->Form->control('idp_entity_id', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
            <div class="col-md-6"><label class="form-label small mb-0">IdP SSO-URL</label>
                <?= $this->Form->control('idp_sso_url', ['label' => false, 'class' => 'form-control form-control-sm']) ?></div>
            <div class="col-12"><label class="form-label small mb-0"><?= h(__('admin.integrations.sso_idp_cert')) ?></label>
                <?= $this->Form->control('idp_x509cert', ['label' => false, 'type' => 'textarea', 'rows' => 3, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => '-----BEGIN CERTIFICATE-----']) ?></div>
        </div>
        <?= $this->Form->button(__('admin.integrations.add'), ['class' => 'btn btn-sm btn-primary']) ?>
        <span class="text-muted small ms-2"><?= h(__('admin.integrations.sso_sp_hint')) ?></span>
    <?= $this->Form->end() ?>
    <script>
    (function () {
        var sel = document.getElementById('sso-type');
        function sync() {
            var saml = sel.value === 'saml';
            document.getElementById('sso-oidc').hidden = saml;
            document.getElementById('sso-saml').hidden = !saml;
        }
        sel.addEventListener('change', sync);
        sync();
    })();
    </script>
            </div>
        </div>
    </div>
</div>

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
                <?= $this->UiKit->confirmPost(__('admin.integrations.delete'), ['action' => 'automationDelete', $r['id']], __('admin.integrations.confirm_delete'), ['class' => 'btn btn-sm btn-outline-danger']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="accordion mt-2" id="automationAdd">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#automationForm" aria-expanded="false" aria-controls="automationForm"><?= h(__('admin.integrations.automation_add')) ?></button>
        </h2>
        <div id="automationForm" class="accordion-collapse collapse">
            <div class="accordion-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'automationCreate']]) ?>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6"><label class="form-label small mb-0">Name</label>
                            <?= $this->Form->control('name', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
                        <div class="col-md-6"><label class="form-label small mb-0"><?= h(__('admin.integrations.event_pattern')) ?></label>
                            <?= $this->Form->control('event', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm', 'placeholder' => 'ticket.created | user.* | *']) ?></div>
                    </div>
                    <div class="mb-2"><label class="form-label small mb-0"><?= h(__('admin.integrations.condition_json')) ?></label>
                        <?= $this->Form->control('condition', ['label' => false, 'type' => 'textarea', 'rows' => 2, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => '{"field":"data.priority","op":"eq","value":"high"}']) ?></div>
                    <div class="mb-2"><label class="form-label small mb-0"><?= h(__('admin.integrations.actions_json')) ?></label>
                        <?= $this->Form->control('actions', ['label' => false, 'type' => 'textarea', 'rows' => 2, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => '[{"type":"notify","user_field":"user_id","title":"…"}]']) ?></div>
                    <?= $this->Form->button(__('admin.integrations.add'), ['class' => 'btn btn-sm btn-primary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

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
                <?= $this->UiKit->confirmPost(__('admin.integrations.delete'), ['action' => 'workflowDelete', $w['id']], __('admin.integrations.confirm_delete'), ['class' => 'btn btn-sm btn-outline-danger']) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>

<div class="accordion mt-2" id="workflowAdd">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#workflowForm" aria-expanded="false" aria-controls="workflowForm"><?= h(__('admin.integrations.workflow_add')) ?></button>
        </h2>
        <div id="workflowForm" class="accordion-collapse collapse">
            <div class="accordion-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'workflowCreate'], 'class' => 'row g-2 align-items-end']) ?>
                    <div class="col-md-3"><label class="form-label small mb-0">Name</label>
                        <?= $this->Form->control('name', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm']) ?></div>
                    <div class="col-md-3"><label class="form-label small mb-0">Entity-Type</label>
                        <?= $this->Form->control('entity_type', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm', 'placeholder' => 'ticket']) ?></div>
                    <div class="col-md-3"><label class="form-label small mb-0"><?= h(__('admin.integrations.entity_id_field')) ?></label>
                        <?= $this->Form->control('entity_id_field', ['label' => false, 'class' => 'form-control form-control-sm', 'placeholder' => 'entity_id']) ?></div>
                    <div class="col-md-3"><label class="form-label small mb-0"><?= h(__('admin.integrations.initial_state')) ?></label>
                        <?= $this->Form->control('initial_state', ['label' => false, 'required' => true, 'class' => 'form-control form-control-sm', 'placeholder' => 'open']) ?></div>
                    <div class="col-12"><label class="form-label small mb-0"><?= h(__('admin.integrations.transitions_json')) ?></label>
                        <?= $this->Form->control('transitions', ['label' => false, 'type' => 'textarea', 'rows' => 2, 'class' => 'form-control form-control-sm font-monospace', 'placeholder' => '[{"from":"open","to":"closed","on":"close"}]']) ?></div>
                    <div class="col-auto"><?= $this->Form->button(__('admin.integrations.add'), ['class' => 'btn btn-sm btn-primary']) ?></div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>
</div>

<p class="text-muted small mt-4"><?= h(__('admin.integrations.cli_hint')) ?></p>
