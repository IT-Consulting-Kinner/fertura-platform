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
    <h1 class="h3 mb-0"><?= h(__('admin.health.title')) ?></h1>
    <span class="badge text-bg-<?= $badge[$report['status']] ?? 'secondary' ?> fs-6"><?= h(strtoupper($report['status'])) ?></span>
</div>
<p class="text-muted small"><?= __('admin.health.intro', '<code>/health</code>', '<code>/health/detail</code>') ?></p>

<div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th><?= h(__('admin.health.col_subsystem')) ?></th><th><?= h(__('admin.health.col_status')) ?></th><th><?= h(__('admin.health.col_detail')) ?></th></tr></thead>
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

<h2 class="h5 mt-4"><?= h(__('admin.health.workers_heading')) ?></h2>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th><?= h(__('admin.health.col_worker')) ?></th><th><?= h(__('admin.health.col_last_run')) ?></th><th><?= h(__('admin.health.col_age')) ?></th><th><?= h(__('admin.health.col_duration')) ?></th><th><?= h(__('admin.health.col_status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($heartbeats as $hb):
        $age = (int)$hb['age_seconds'];
        $d = is_string($hb['detail'] ?? null) ? (json_decode((string)$hb['detail'], true) ?: []) : (array)($hb['detail'] ?? []);
        $interval = (int)($d['interval_seconds'] ?? 0);
        $overdue = $interval > 0 && $age > 2 * $interval;
    ?>
        <tr>
            <td><code><?= h($hb['worker_key']) ?></code><?php if ($interval > 0): ?> <span class="text-muted small"><?= $interval ?>s</span><?php endif; ?></td>
            <td class="small"><?= h((string)$hb['last_run_at']) ?></td>
            <td><?= $age ?> s<?php if ($overdue): ?> <span class="badge text-bg-warning"><?= h(__('admin.health.overdue')) ?></span><?php endif; ?></td>
            <td class="small"><?= isset($d['duration_ms']) ? (int)$d['duration_ms'] . ' ms' : '–' ?></td>
            <td><span class="badge text-bg-<?= $hb['last_status'] === 'ok' ? 'success' : ($hb['last_status'] === 'warn' ? 'warning' : 'danger') ?>"><?= h($hb['last_status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($heartbeats === []): ?><tr><td colspan="5" class="text-muted"><?= h(__('admin.health.workers_empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
