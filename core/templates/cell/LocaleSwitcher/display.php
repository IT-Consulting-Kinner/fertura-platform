<?php
/**
 * @var \App\View\AppView $this
 * @var list<string> $locales
 * @var string $current
 * @var bool $persist
 * @var string $style  'buttons' (no-JS inline buttons, public/login) | 'select'
 *                      (a select that switches on change, admin shell)
 */
use App\Service\I18n\LocaleResolver;

if (count($locales) < 2) {
    return; // nichts umzuschalten
}

// A select showing the active language, switching on change. Logged-in (persist):
// POST /locale/change (stored as user.locale). Anonymous (login/public): GET
// ?lang= (session-only switch, handled by LocaleMiddleware) — no form/CSRF needed.
if (($style ?? 'buttons') === 'select') {
    $options = [];
    foreach ($locales as $loc) {
        $options[$loc] = LocaleResolver::displayName($loc);
    }
    if ($persist) {
        echo $this->Form->create(null, ['url' => '/locale/change', 'class' => 'm-0']);
        echo $this->Form->control('lang', [
            'type' => 'select',
            'options' => $options,
            'value' => $current,
            'label' => false,
            'class' => 'form-select form-select-sm locale-select',
            'onchange' => 'this.form.submit()',
            'aria-label' => __('locale.switch'),
        ]);
        echo $this->Form->end();
    } else {
        $out = '<select class="form-select form-select-sm locale-select" aria-label="'
            . h(__('locale.switch'))
            . '" onchange="location.href=\'?lang=\'+encodeURIComponent(this.value)">';
        foreach ($options as $code => $name) {
            $out .= sprintf(
                '<option value="%s"%s>%s</option>',
                h((string)$code),
                $code === $current ? ' selected' : '',
                h((string)$name),
            );
        }
        $out .= '</select>';
        echo $out;
    }

    return;
}
?>
<span class="d-inline-flex align-items-center gap-1" title="<?= h(__('locale.switch')) ?>">
    <?php foreach ($locales as $loc): ?>
        <?php $isCur = $loc === $current; ?>
        <?php if ($isCur): ?>
            <span class="btn btn-sm btn-light disabled" aria-current="true"><?= h(LocaleResolver::displayName($loc)) ?></span>
        <?php elseif ($persist): ?>
            <?= $this->Form->create(null, ['url' => '/locale/change', 'class' => 'm-0 d-inline']) ?>
            <?= $this->Form->hidden('lang', ['value' => $loc]) ?>
            <button type="submit" class="btn btn-sm btn-outline-light" title="<?= h($loc) ?>"><?= h(LocaleResolver::displayName($loc)) ?></button>
            <?= $this->Form->end() ?>
        <?php else: ?>
            <a class="btn btn-sm btn-outline-light" href="?lang=<?= h($loc) ?>" title="<?= h($loc) ?>"><?= h(LocaleResolver::displayName($loc)) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</span>
