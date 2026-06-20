<?php
/**
 * Bootstrap 5 breadcrumb for the admin shell: top menu → section → function.
 * Rendered once by the admin layout from the `breadcrumb` the controller sets;
 * an empty list renders nothing (dashboard / area-less system pages).
 *
 * @var \App\View\AppView $this
 * @var list<array{0:string,1:?string}> $crumbs  [label-key, url|null]; null = current
 */
if (($crumbs ?? []) === []) {
    return;
}
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <?php foreach ($crumbs as $crumb): ?>
            <?php if ($crumb[1] !== null): ?>
                <li class="breadcrumb-item"><a class="text-decoration-none" href="<?= h($crumb[1]) ?>"><?= h(__($crumb[0])) ?></a></li>
            <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page"><?= h(__($crumb[0])) ?></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
