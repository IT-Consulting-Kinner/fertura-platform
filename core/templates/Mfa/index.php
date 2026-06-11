<?php
/**
 * @var \App\View\AppView $this
 * @var bool $enabled
 * @var int $recoveryLeft
 * @var bool $required
 * @var list<array{id:string,credential_id:string,label:?string,created_at:string,last_used_at:?string}> $passkeys
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('mfa.index.title')) ?></h1>
</div>
<div class="card"><div class="card-body" style="max-width:560px">
<?php if ($enabled): ?>
    <p>
        <span class="badge text-bg-success"><?= h(__('mfa.index.enabled')) ?></span>
        <span class="ms-2 text-muted"><?= h(__('mfa.index.recovery_left', (string)$recoveryLeft)) ?></span>
    </p>
    <?= $this->Form->create(null, ['url' => '/mfa/disable']) ?>
        <div class="mb-3">
            <label class="form-label" for="code"><?= h(__('mfa.index.disable_code')) ?></label>
            <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control', 'required' => true, 'autocomplete' => 'one-time-code']) ?>
        </div>
        <?= $this->Form->button(__('mfa.index.disable'), ['class' => 'btn btn-outline-danger']) ?>
    <?= $this->Form->end() ?>

    <hr class="my-4">
    <h2 class="h5"><?= h(__('mfa.passkeys.title')) ?></h2>
    <p class="text-muted"><?= h(__('mfa.passkeys.intro')) ?></p>
    <?php if (!empty($passkeys)): ?>
        <table class="table table-sm align-middle">
            <thead><tr>
                <th scope="col"><?= h(__('mfa.passkeys.col_label')) ?></th>
                <th scope="col"><?= h(__('mfa.passkeys.col_last_used')) ?></th>
                <th scope="col" class="text-end"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($passkeys as $pk): ?>
                <tr>
                    <td><?= h($pk['label'] ?? __('mfa.passkeys.unnamed')) ?></td>
                    <td class="text-muted"><?= h($pk['last_used_at'] ?? '—') ?></td>
                    <td class="text-end">
                        <?= $this->Form->postLink(__('mfa.passkeys.remove'), '/mfa/passkeyDelete/' . $pk['id'], ['class' => 'btn btn-sm btn-outline-danger']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <div class="row g-2 align-items-end" id="passkey-add">
        <div class="col-auto">
            <label class="form-label" for="pk-label"><?= h(__('mfa.passkeys.label')) ?></label>
            <input type="text" id="pk-label" class="form-control" maxlength="60">
        </div>
        <div class="col-auto">
            <button type="button" id="passkey-add-btn" class="btn btn-outline-primary"><?= h(__('mfa.passkeys.add')) ?></button>
        </div>
    </div>
    <?= $this->Form->create(null, ['url' => '/mfa/passkeyRegister', 'id' => 'passkey-register-form']) ?>
        <?= $this->Form->hidden('client_data', ['id' => 'pkr-cd']) ?>
        <?= $this->Form->hidden('attestation', ['id' => 'pkr-att']) ?>
        <?= $this->Form->hidden('label', ['id' => 'pkr-label']) ?>
    <?= $this->Form->end() ?>
    <script>
    (function () {
        var b64u = {
            dec: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); var b = atob(s); var a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a.buffer; },
            enc: function (buf) { var a = new Uint8Array(buf), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
        };
        document.getElementById('passkey-add-btn').addEventListener('click', function () {
            fetch('/mfa/passkeyOptions', {headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.json(); })
                .then(function (o) {
                    o.challenge = b64u.dec(o.challenge);
                    o.user.id = b64u.dec(o.user.id);
                    (o.excludeCredentials || []).forEach(function (c) { c.id = b64u.dec(c.id); });
                    return navigator.credentials.create({publicKey: o});
                })
                .then(function (cred) {
                    document.getElementById('pkr-cd').value = b64u.enc(cred.response.clientDataJSON);
                    document.getElementById('pkr-att').value = b64u.enc(cred.response.attestationObject);
                    document.getElementById('pkr-label').value = document.getElementById('pk-label').value;
                    document.getElementById('passkey-register-form').submit();
                })
                .catch(function () { /* vom Benutzer abgebrochen */ });
        });
    })();
    </script>
<?php else: ?>
    <p>
        <span class="badge text-bg-secondary"><?= h(__('mfa.index.disabled')) ?></span>
        <?php if ($required): ?>
            <span class="ms-2 text-danger"><?= h(__('mfa.index.required_hint')) ?></span>
        <?php endif; ?>
    </p>
    <p class="text-muted"><?= h(__('mfa.index.intro')) ?></p>
    <?= $this->Form->create(null, ['url' => '/mfa/setup']) ?>
        <?= $this->Form->button(__('mfa.index.enable'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
<?php endif; ?>
</div></div>
