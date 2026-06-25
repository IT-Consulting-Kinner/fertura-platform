<?php
/**
 * @var \App\View\AppView $this
 * @var array{
 *     tenant_id:string,
 *     modules:list<array{module_key:string,name:string,version:string}>,
 *     module_count:int,
 *     storage:array{backup_bytes:int,backup_count:int,file_bytes:int,file_count:int,total_bytes:int},
 *     footprint:array{total_rows:int,tables:list<array{key:string,rows:int}>}
 * }|null $summary
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
<h1 class="h3 mb-1"><?= h(__('admin.consumption.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.consumption.hint')) ?></p>

<?php if ($summary === null) : ?>
    <div class="alert alert-warning"><?= h(__('admin.consumption.no_tenant')) ?></div>
<?php else : ?>
    <?php $s = $summary['storage']; ?>
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small"><?= h(__('admin.consumption.kpi_functions')) ?></div>
                <div class="h4 mb-0"><?= h((string)$summary['module_count']) ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small"><?= h(__('admin.consumption.kpi_storage_total')) ?></div>
                <div class="h4 mb-0"><?= h($bytes($s['total_bytes'])) ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small"><?= h(__('admin.consumption.kpi_backups')) ?></div>
                <div class="h4 mb-0"><?= h($bytes($s['backup_bytes'])) ?></div>
                <div class="text-muted small"><?= h(__('admin.consumption.count_archives', (string)$s['backup_count'])) ?></div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small"><?= h(__('admin.consumption.kpi_files')) ?></div>
                <div class="h4 mb-0"><?= h($bytes($s['file_bytes'])) ?></div>
                <div class="text-muted small"><?= h(__('admin.consumption.count_files', (string)$s['file_count'])) ?></div>
            </div></div>
        </div>
    </div>

    <h2 class="h5 mb-1"><?= h(__('admin.consumption.functions_title')) ?></h2>
    <p class="text-muted small"><?= h(__('admin.consumption.functions_hint')) ?></p>
    <table class="table table-sm table-hover align-middle">
        <thead><tr>
            <th scope="col"><?= h(__('admin.consumption.col_module')) ?></th>
            <th scope="col"><?= h(__('admin.consumption.col_key')) ?></th>
            <th scope="col"><?= h(__('admin.consumption.col_version')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($summary['modules'] as $m) : ?>
            <tr>
                <td class="small"><?= h($m['name']) ?></td>
                <td class="small text-muted"><code><?= h($m['module_key']) ?></code></td>
                <td class="small text-muted"><?= h($m['version']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($summary['modules'] === []) : ?>
            <tr><td colspan="3" class="text-muted"><?= h(__('admin.consumption.no_modules')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <hr class="my-4">

    <h2 class="h5 mb-1"><?= h(__('admin.consumption.footprint_title')) ?></h2>
    <p class="text-muted small">
        <?= h(__('admin.consumption.footprint_hint')) ?>
        <?= h(__('admin.consumption.total_rows', (string)$summary['footprint']['total_rows'])) ?>
    </p>
    <table class="table table-sm table-hover align-middle">
        <thead><tr>
            <th scope="col"><?= h(__('admin.consumption.col_table')) ?></th>
            <th scope="col" class="text-end"><?= h(__('admin.consumption.col_rows')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($summary['footprint']['tables'] as $t) : ?>
            <tr>
                <td class="small"><code><?= h($t['key']) ?></code></td>
                <td class="small text-end"><?= h(number_format((float)$t['rows'], 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($summary['footprint']['tables'] === []) : ?>
            <tr><td colspan="2" class="text-muted"><?= h(__('admin.consumption.no_data')) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
<?php endif; ?>
