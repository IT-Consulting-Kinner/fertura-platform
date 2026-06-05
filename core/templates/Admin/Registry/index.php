<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $contracts
 * @var array<int, array<string, mixed>> $registrations
 * @var array<int, array<string, mixed>> $bindings
 */
$typeBadge = ['resolver' => 'primary', 'collector' => 'info', 'event' => 'warning', 'service' => 'success'];
?>
<h1 class="h3 mb-3">Contract-Registry</h1>

<h2 class="h5">Contracts</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Name</th><th>Typ</th><th>Version</th><th>Eigentümer-Modul</th><th>Mehrfach</th><th>Registrierungen</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($contracts as $c): ?>
        <tr>
            <td><code><?= h($c['name']) ?></code></td>
            <td><span class="badge text-bg-<?= $typeBadge[$c['contract_type']] ?? 'secondary' ?>"><?= h($c['contract_type']) ?></span></td>
            <td><?= h($c['version']) ?></td>
            <td><?= h($c['owner_module_key']) ?></td>
            <td><?= filter_var($c['multi_use'], FILTER_VALIDATE_BOOLEAN) ? 'ja' : 'nein' ?></td>
            <td><?= (int)$c['reg_count'] ?></td>
            <td><?= filter_var($c['active'], FILTER_VALIDATE_BOOLEAN) ? '<span class="badge text-bg-success">aktiv</span>' : '<span class="badge text-bg-secondary">inaktiv</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($contracts === []): ?><tr><td colspan="7" class="text-muted">Keine Contracts registriert.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4">Registrierungen</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Contract</th><th>Modul</th><th>Typ</th><th>Implementierung</th><th>Priorität</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($registrations as $r): ?>
        <tr>
            <td><code><?= h($r['contract']) ?></code></td>
            <td><?= h($r['module_key']) ?></td>
            <td><?= h($r['registration_type']) ?></td>
            <td class="small text-muted"><?= h($r['implementation_class']) ?: '–' ?></td>
            <td><?= (int)$r['priority'] ?></td>
            <td><?= filter_var($r['active'], FILTER_VALIDATE_BOOLEAN) ? 'aktiv' : 'inaktiv' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($registrations === []): ?><tr><td colspan="6" class="text-muted">Keine Registrierungen.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4">Capability-Bindings</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Modul</th><th>Contract</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($bindings as $b): ?>
        <tr><td><?= h($b['module_key']) ?></td><td><code><?= h($b['contract']) ?></code></td>
            <td><span class="badge text-bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= h($b['status']) ?></span></td></tr>
    <?php endforeach; ?>
    <?php if ($bindings === []): ?><tr><td colspan="3" class="text-muted">Keine Bindings.</td></tr><?php endif; ?>
    </tbody>
</table>
