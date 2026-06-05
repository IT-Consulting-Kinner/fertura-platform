<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $interfaces
 * @var array<int, array<string, mixed>> $usages
 */
$b = static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
$spec = static function ($v): string {
    if ($v === null || $v === '') {
        return '–';
    }
    $d = is_string($v) ? json_decode($v, true) : $v;

    return $d === null ? (string)$v : (string)json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Interface-Registry</h1>
    <?= $this->Html->link('Alle Contracts', ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>
<p class="text-muted small">Öffentliche Modul-Interfaces = Service-Contracts (Kap. 29). Anbietende Main-Module stellen sie bereit, andere Module nutzen sie über Capability-Handles.</p>

<h2 class="h5">Angebotene Interfaces</h2>
<?php foreach ($interfaces as $i): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><code><?= h($i['name']) ?></code> <span class="badge text-bg-secondary">v<?= h($i['version']) ?></span></span>
            <span>
                <?php if ($b($i['active']) && $i['provider'] !== null): ?>
                    <span class="badge text-bg-success">verfügbar</span>
                <?php else: ?>
                    <span class="badge text-bg-warning">nicht verfügbar</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body">
            <p class="mb-2"><?= h($i['description']) ?: '<span class="text-muted">Keine Beschreibung.</span>' ?></p>
            <dl class="row mb-0 small">
                <dt class="col-sm-3">Anbieter-Modul</dt><dd class="col-sm-9"><?= h($i['owner_module_key']) ?></dd>
                <dt class="col-sm-3">Provider aktiv</dt><dd class="col-sm-9"><?= $i['provider'] !== null ? h($i['provider']) : '<span class="text-warning">keiner</span>' ?></dd>
                <dt class="col-sm-3">Mehrfachnutzung</dt><dd class="col-sm-9"><?= $b($i['multi_use']) ? 'erlaubt' : '<strong>exklusiv (nein)</strong>' ?></dd>
                <dt class="col-sm-3">Aktive Nutzer</dt><dd class="col-sm-9"><?= (int)$i['active_consumers'] ?></dd>
                <dt class="col-sm-3">Eingabe-Spezifikation</dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['input_spec'])) ?></code></dd>
                <dt class="col-sm-3">Ausgabe-Spezifikation</dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['output_spec'])) ?></code></dd>
                <dt class="col-sm-3">Fehlerverhalten</dt><dd class="col-sm-9"><code class="small"><?= h($spec($i['default_behavior'])) ?></code></dd>
            </dl>
        </div>
    </div>
<?php endforeach; ?>
<?php if ($interfaces === []): ?><p class="text-muted">Keine öffentlichen Modul-Interfaces registriert.</p><?php endif; ?>

<h2 class="h5 mt-4">Nutzung je Modul</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Nutzendes Modul</th><th>Modulversion</th><th>Ziel-Interface</th><th>Interface-Version</th><th>gefordert</th><th>Status</th><th>Kompatibilität</th></tr></thead>
    <tbody>
    <?php foreach ($usages as $u): $active = $b($u['active']) && $u['binding_status'] === 'active'; ?>
        <tr>
            <td><?= h($u['module_key']) ?></td>
            <td><?= h($u['module_version']) ?: '–' ?></td>
            <td><code class="small"><?= h($u['interface']) ?></code></td>
            <td><?= h($u['interface_version']) ?></td>
            <td><?= h($u['required_version']) ?: '–' ?></td>
            <td><span class="badge text-bg-<?= $active ? 'success' : 'secondary' ?>"><?= $active ? 'aktiv' : 'inaktiv' ?></span></td>
            <td><?= $b($u['active']) ? '<span class="badge text-bg-success">kompatibel</span>' : '<span class="badge text-bg-secondary">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($usages === []): ?><tr><td colspan="7" class="text-muted">Keine registrierten Nutzungen.</td></tr><?php endif; ?>
    </tbody>
</table>
