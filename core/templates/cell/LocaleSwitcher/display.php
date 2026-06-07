<?php
/**
 * @var \App\View\AppView $this
 * @var list<string> $locales
 * @var string $current
 * @var bool $persist
 *
 * No-JS-Umschalter (die Layouts laden kein Bootstrap-JS): kleine Inline-Buttons.
 */
use App\Service\I18n\LocaleResolver;

if (count($locales) < 2) {
    return; // nichts umzuschalten
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
