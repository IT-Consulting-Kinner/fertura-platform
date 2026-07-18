<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{key: string, name: string, tiles: list<array<string, mixed>>}> $groups
 * @var bool $isOperator
 */
$this->assign('title', __('admin.dashboard.title'));

/** Renders one group's tiles as a Bootstrap card grid. */
$tileGrid = function (array $tiles): string {
    $out = '<div class="row g-3">';
    foreach ($tiles as $t) {
        $variant = isset($t['variant']) ? ' border-' . h((string)$t['variant']) : '';
        $inner = '<div class="text-muted small text-uppercase">' . h((string)$t['label']) . '</div>'
            . '<div class="display-6">' . h((string)$t['value']) . '</div>';
        // An app-relative url (coerced) makes the whole tile a link.
        if (isset($t['url'])) {
            $body = '<a class="card-body text-decoration-none text-reset stretched-link" href="'
                . h((string)$t['url']) . '">' . $inner . '</a>';
        } else {
            $body = '<div class="card-body">' . $inner . '</div>';
        }
        $out .= '<div class="col-sm-6 col-lg-3"><div class="card h-100' . $variant . '">' . $body . '</div></div>';
    }

    return $out . '</div>';
};
?>
<h1 class="h3 mb-4"><?= h(__('admin.dashboard.title')) ?></h1>

<?php foreach ($groups as $group): ?>
    <?= $this->UiKit->formAccordion(
        (string)$group['name'],
        $tileGrid($group['tiles']),
        ['open' => true, 'id' => 'dash-' . (string)preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$group['key'])],
    ) ?>
<?php endforeach; ?>
