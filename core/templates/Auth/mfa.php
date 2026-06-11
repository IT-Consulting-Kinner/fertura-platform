<?php
/**
 * @var \App\View\AppView $this
 * @var bool $hasPasskeys
 */
?>
<h1 class="h5 mb-3"><?= h(__('mfa.challenge.title')) ?></h1>
<p class="text-muted"><?= h(__('mfa.challenge.hint')) ?></p>
<?php if (!empty($hasPasskeys)): ?>
    <div class="d-grid mb-3">
        <button type="button" id="passkey-btn" class="btn btn-outline-primary"><?= h(__('mfa.challenge.passkey')) ?></button>
    </div>
    <?= $this->Form->create(null, ['url' => '/login/mfa', 'id' => 'passkey-form']) ?>
        <?= $this->Form->hidden('credential_id', ['id' => 'pk-cred']) ?>
        <?= $this->Form->hidden('client_data', ['id' => 'pk-cd']) ?>
        <?= $this->Form->hidden('auth_data', ['id' => 'pk-ad']) ?>
        <?= $this->Form->hidden('signature', ['id' => 'pk-sig']) ?>
    <?= $this->Form->end() ?>
    <script>
    (function () {
        var b64u = {
            dec: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); var b = atob(s); var a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a.buffer; },
            enc: function (buf) { var a = new Uint8Array(buf), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
        };
        document.getElementById('passkey-btn').addEventListener('click', function () {
            fetch('/login/mfa/passkeys', {headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.json(); })
                .then(function (o) {
                    o.challenge = b64u.dec(o.challenge);
                    (o.allowCredentials || []).forEach(function (c) { c.id = b64u.dec(c.id); });
                    return navigator.credentials.get({publicKey: o});
                })
                .then(function (cred) {
                    document.getElementById('pk-cred').value = b64u.enc(cred.rawId);
                    document.getElementById('pk-cd').value = b64u.enc(cred.response.clientDataJSON);
                    document.getElementById('pk-ad').value = b64u.enc(cred.response.authenticatorData);
                    document.getElementById('pk-sig').value = b64u.enc(cred.response.signature);
                    document.getElementById('passkey-form').submit();
                })
                .catch(function () { /* abgebrochen -> Code-Eingabe bleibt verfügbar */ });
        });
    })();
    </script>
    <hr class="my-3">
<?php endif; ?>
<?= $this->Form->create(null, ['url' => '/login/mfa']) ?>
    <div class="mb-3">
        <label class="form-label" for="code"><?= h(__('mfa.challenge.code')) ?></label>
        <?= $this->Form->control('code', [
            'label' => false, 'class' => 'form-control', 'autofocus' => true, 'required' => true,
            'autocomplete' => 'one-time-code', 'inputmode' => 'numeric',
        ]) ?>
        <div class="form-text"><?= h(__('mfa.challenge.recovery_hint')) ?></div>
    </div>
    <?= $this->Form->button(__('mfa.challenge.submit'), ['class' => 'btn btn-primary w-100']) ?>
<?= $this->Form->end() ?>
<p class="text-center mt-3 mb-0"><a href="/login"><?= h(__('mfa.challenge.back')) ?></a></p>
