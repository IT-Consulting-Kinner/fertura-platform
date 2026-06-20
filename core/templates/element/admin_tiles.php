<?php
/**
 * Reusable tile grid for the tile-based admin navigation. Each tile is a card
 * link with a name, an optional sub-line (contained items / description) and an
 * optional count badge.
 *
 * @var \App\View\AppView $this
 * @var list<array{label:string, url:string, sub?:?string, badge?:?string}> $tiles
 */
?>
<?php if ($tiles === []): ?>
    <p class="text-muted"><?= h(__('admin.nav.tiles_empty')) ?></p>
<?php else: ?>
<div class="tile-grid">
<?php foreach ($tiles as $tile): ?>
    <a class="card text-decoration-none text-reset shadow-sm" href="<?= h($tile['url']) ?>">
        <div class="card-body d-flex justify-content-between align-items-start">
            <div class="pe-2">
                <div class="h5 mb-1"><?= h($tile['label']) ?></div>
                <?php if (!empty($tile['sub'])): ?>
                    <div class="text-muted small"><?= h($tile['sub']) ?></div>
                <?php endif; ?>
            </div>
            <?php if (isset($tile['badge']) && (string)$tile['badge'] !== ''): ?>
                <span class="badge text-bg-primary rounded-pill fs-6"><?= h((string)$tile['badge']) ?></span>
            <?php endif; ?>
        </div>
    </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
