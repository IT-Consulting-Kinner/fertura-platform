<?php
/**
 * @var \App\View\AppView $this
 * @var array{ok:bool,error:?string,count:int,exists:bool,existing_edited:bool,sample:list<string>} $preview
 * @var string $token
 * @var string $component
 * @var string $version
 * @var string $locale
 * @var string $domain
 * @var string $type
 */
?>
<h1 class="h4 mb-3"><?= h(__('admin.localization.import_review_title')) ?></h1>

<div class="alert alert-warning"><?= h(__('admin.localization.import_unsigned_note')) ?></div>

<ul class="list-group mb-3" style="max-width:720px">
    <li class="list-group-item"><?= h(__('admin.localization.target_component')) ?>:
        <strong><?= h($component) ?></strong> · v<?= h($version) ?> · <code><?= h($locale) ?></code> · <?= h($type) ?></li>
    <li class="list-group-item"><?= h(__('admin.localization.entry_count')) ?>: <strong><?= (int)$preview['count'] ?></strong></li>
    <?php if ($preview['exists']): ?>
        <li class="list-group-item list-group-item-warning"><?= h(__('admin.localization.import_overwrite')) ?></li>
    <?php endif; ?>
    <?php if ($preview['existing_edited']): ?>
        <li class="list-group-item list-group-item-danger"><?= h(__('admin.localization.import_edited_warning')) ?></li>
    <?php endif; ?>
</ul>

<?php if ($preview['sample'] !== []): ?>
    <p class="small text-muted mb-1"><?= h(__('admin.localization.sample_keys')) ?>:</p>
    <p class="small"><?php foreach ($preview['sample'] as $k): ?><code><?= h($k) ?></code> <?php endforeach; ?></p>
<?php endif; ?>

<div class="d-flex gap-2">
    <?= $this->Form->create(null, ['url' => ['action' => 'import']]) ?>
    <?= $this->Form->hidden('step', ['value' => 'commit']) ?>
    <?= $this->Form->hidden('token', ['value' => $token]) ?>
    <?= $this->Form->hidden('component', ['value' => $component]) ?>
    <?= $this->Form->hidden('version', ['value' => $version]) ?>
    <?= $this->Form->hidden('locale', ['value' => $locale]) ?>
    <?= $this->Form->hidden('domain', ['value' => $domain]) ?>
    <?= $this->Form->hidden('type', ['value' => $type]) ?>
    <?= $this->Form->button(__('admin.localization.confirm_import'), ['class' => 'btn btn-primary']) ?>
    <?= $this->Form->end() ?>
    <?= $this->Html->link(__('admin.localization.cancel'), ['action' => 'import'], ['class' => 'btn btn-outline-secondary']) ?>
</div>
