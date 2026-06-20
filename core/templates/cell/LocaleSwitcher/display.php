<?php
/**
 * @var \App\View\AppView $this
 * @var list<string> $locales
 * @var string $current
 * @var bool $persist
 * @var string $style  'select' (a dropdown switching on change — GET form on the
 *                      login mask, POST in the admin shell) | 'buttons' (legacy
 *                      no-JS inline button list)
 */
use App\Service\I18n\LocaleResolver;

if (count($locales) < 2) {
    return; // nichts umzuschalten
}

// Select style: a dropdown showing the active language, switching on change.
if (($style ?? 'buttons') === 'select') {
    $options = [];
    foreach ($locales as $loc) {
        $options[$loc] = LocaleResolver::displayName($loc);
    }

    if ($persist) {
        // Admin shell (authenticated): the choice is persisted (POST to
        // /locale/change → session + user.locale + remembering cookie).
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

        return;
    }

    // Public/login (anonymous): a GET form back to the current page switches via
    // ?lang (LocaleMiddleware applies it + drops the remembering cookie),
    // auto-submitting on change (like the admin shell). Existing query params
    // (e.g. ?redirect=) are carried over as hidden fields so the GET submit
    // (which drops the action's query string) keeps them.
    echo $this->Form->create(null, ['type' => 'get', 'url' => $this->request->getPath(), 'class' => 'm-0']);
    foreach ($this->request->getQueryParams() as $k => $v) {
        if ($k !== 'lang' && is_string($v)) {
            echo $this->Form->hidden($k, ['value' => $v]);
        }
    }
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
