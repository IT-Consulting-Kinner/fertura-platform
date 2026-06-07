<?php
/**
 * @var \App\View\AppView $this
 * @var list<array<string,mixed>> $nodes
 * @var list<array<string,float>> $edges
 * @var int $width
 * @var int $height
 */
$fill = static fn (string $status): string => match ($status) {
    'active' => '#198754',
    'inactive', 'installed_inactive' => '#6c757d',
    default => '#0d6efd',
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><?= h(__('admin.modules.graph_title')) ?></h1>
    <?= $this->Html->link(__('admin.modules.back_to_list'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>
<p class="text-muted small"><?= h(__('admin.modules.graph_intro')) ?></p>

<?php if ($nodes === []): ?>
    <div class="alert alert-info"><?= h(__('admin.modules.graph_empty')) ?></div>
<?php else: ?>
<div class="border rounded bg-white p-2" style="overflow:auto">
    <svg width="<?= (int)$width ?>" height="<?= (int)$height ?>" viewBox="0 0 <?= (int)$width ?> <?= (int)$height ?>" xmlns="http://www.w3.org/2000/svg" role="img">
        <defs>
            <marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="3" orient="auto" markerUnits="strokeWidth">
                <path d="M0,0 L7,3 L0,6 Z" fill="#adb5bd"></path>
            </marker>
        </defs>
        <?php foreach ($edges as $e): ?>
            <line x1="<?= (float)$e['x1'] ?>" y1="<?= (float)$e['y1'] ?>" x2="<?= (float)$e['x2'] ?>" y2="<?= (float)$e['y2'] ?>"
                  stroke="#adb5bd" stroke-width="1.5" marker-end="url(#arrow)"></line>
        <?php endforeach; ?>
        <?php foreach ($nodes as $n): ?>
            <g>
                <rect x="<?= (int)$n['x'] ?>" y="<?= (int)$n['y'] ?>" width="<?= (int)$n['w'] ?>" height="<?= (int)$n['h'] ?>"
                      rx="6" fill="#fff" stroke="<?= $fill((string)$n['status']) ?>" stroke-width="2"></rect>
                <rect x="<?= (int)$n['x'] ?>" y="<?= (int)$n['y'] ?>" width="6" height="<?= (int)$n['h'] ?>"
                      rx="3" fill="<?= $fill((string)$n['status']) ?>"></rect>
                <text x="<?= (int)$n['x'] + 14 ?>" y="<?= (int)$n['y'] + 19 ?>" font-size="12" font-weight="600" fill="#212529"><?= h(mb_strimwidth((string)$n['name'], 0, 22, '…')) ?></text>
                <text x="<?= (int)$n['x'] + 14 ?>" y="<?= (int)$n['y'] + 35 ?>" font-size="10" fill="#6c757d"><?= h((string)$n['key']) ?> · v<?= h((string)$n['version']) ?></text>
            </g>
        <?php endforeach; ?>
    </svg>
</div>
<div class="small text-muted mt-2"><?= h(__('admin.modules.graph_legend')) ?></div>
<?php endif; ?>
