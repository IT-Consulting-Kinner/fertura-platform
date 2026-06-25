<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{tenant_id:string, name:string, summary:array<string,mixed>|null}> $tenants
 */
// Human-readable byte size (binary units): bytes as a whole number, larger units to
// one decimal with a trailing ".0" trimmed — e.g. 1536 -> "1.5 KB", 51200 -> "50 KB".
$bytes = static function (int $n): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)max(0, $n);
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    $num = $i === 0 ? (string)(int)$v : rtrim(rtrim(number_format($v, 1), '0'), '.');

    return $num . ' ' . $units[$i];
};
?>
<h1 class="h3 mb-1"><?= h(__('admin.operator_consumption.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.operator_consumption.hint')) ?></p>

<table class="table table-sm table-hover align-middle">
    <thead><tr>
        <th scope="col"><?= h(__('admin.operator_consumption.col_tenant')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.operator_consumption.col_functions')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.operator_consumption.col_backups')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.operator_consumption.col_files')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.operator_consumption.col_storage')) ?></th>
        <th scope="col" class="text-end"><?= h(__('admin.operator_consumption.col_rows')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tenants as $t) : ?>
        <?php $s = $t['summary']; ?>
        <tr>
            <td class="small">
                <?= h($t['name']) ?>
                <div class="text-muted"><code><?= h($t['tenant_id']) ?></code></div>
            </td>
            <?php if ($s === null) : ?>
                <td colspan="5" class="small text-muted"><?= h(__('admin.operator_consumption.no_data')) ?></td>
            <?php else : ?>
                <td class="small text-end"><?= h((string)$s['module_count']) ?></td>
                <td class="small text-end"><?= h($bytes((int)$s['storage']['backup_bytes'])) ?></td>
                <td class="small text-end"><?= h($bytes((int)$s['storage']['file_bytes'])) ?></td>
                <td class="small text-end fw-semibold"><?= h($bytes((int)$s['storage']['total_bytes'])) ?></td>
                <td class="small text-end"><?= h(number_format((float)$s['footprint']['total_rows'], 0)) ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if ($tenants === []) : ?>
        <tr><td colspan="6" class="text-muted"><?= h(__('admin.operator_consumption.empty')) ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
