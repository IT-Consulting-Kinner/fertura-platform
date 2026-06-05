<?php
/**
 * @var \App\View\AppView $this
 * @var array{status: string, subsystems: array<string, mixed>} $report
 * @var array<int, array<string, mixed>> $heartbeats
 */
$badge = ['up' => 'success', 'degraded' => 'warning', 'down' => 'danger'];
$render = static function ($detail): string {
    if ($detail === null) {
        return '';
    }
    if (is_scalar($detail)) {
        return (string)$detail;
    }

    return (string)json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Systemstatus</h1>
    <span class="badge text-bg-<?= $badge[$report['status']] ?? 'secondary' ?> fs-6"><?= h(strtoupper($report['status'])) ?></span>
</div>
<p class="text-muted small">Aggregierter Health-Status (Kap. 20.2). Maschinell unter <code>/health</code> (Liveness) bzw. <code>/health/detail</code> (Token/Session) abrufbar.</p>

<div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th>Subsystem</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($report['subsystems'] as $name => $sub): ?>
        <tr>
            <td><strong><?= h($name) ?></strong></td>
            <td><span class="badge text-bg-<?= $badge[$sub['status'] ?? 'up'] ?? 'secondary' ?>"><?= h($sub['status'] ?? '–') ?></span></td>
            <td><code class="small"><?= h($render($sub['detail'] ?? null)) ?></code></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h2 class="h5 mt-4">Worker-Aktualität</h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th>Worker</th><th>Letzter Lauf</th><th>Alter</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($heartbeats as $hb): $age = (int)$hb['age_seconds']; ?>
        <tr>
            <td><code><?= h($hb['worker_key']) ?></code></td>
            <td class="small"><?= h((string)$hb['last_run_at']) ?></td>
            <td><?= $age ?> s</td>
            <td><span class="badge text-bg-<?= $hb['last_status'] === 'ok' ? 'success' : ($hb['last_status'] === 'warn' ? 'warning' : 'danger') ?>"><?= h($hb['last_status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($heartbeats === []): ?><tr><td colspan="4" class="text-muted">Noch kein Worker-Heartbeat vorhanden.</td></tr><?php endif; ?>
    </tbody>
</table>
