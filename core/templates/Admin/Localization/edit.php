<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{index:int,ctx:?string,id:string,plural:?string,msgstr:list<string>,comments:list<string>}> $entries
 * @var array<string,mixed>|null $meta
 * @var string $component
 * @var string $version
 * @var string $locale
 * @var string $domain
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">
        <?= h(__('admin.localization.edit_title')) ?>
        <code class="small"><?= h($component) ?></code> · <code class="small"><?= h($locale) ?></code> · v<?= h($version) ?>
    </h1>
    <?= $this->Html->link(__('admin.localization.back'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>
<p class="text-muted small"><?= h(__('admin.localization.edit_intro')) ?></p>

<?= $this->Form->create(null, ['url' => [
    'action' => 'save',
    '?' => ['component' => $component, 'version' => $version, 'locale' => $locale, 'domain' => $domain],
]]) ?>
<div class="table-responsive">
<table class="table align-middle">
    <thead><tr>
        <th scope="col" style="width:40%"><?= h(__('admin.localization.col_key')) ?></th>
        <th scope="col"><?= h(__('admin.localization.col_translation')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td>
                <?php if ($e['ctx'] !== null): ?><div class="small text-info">ctx: <?= h($e['ctx']) ?></div><?php endif; ?>
                <code><?= h($e['id']) ?></code>
                <?php if ($e['plural'] !== null): ?><div class="small text-muted">plural: <?= h($e['plural']) ?></div><?php endif; ?>
            </td>
            <td>
                <?php foreach ($e['msgstr'] as $i => $val): ?>
                    <?php if ($e['plural'] !== null): ?><label class="small text-muted">[<?= (int)$i ?>]</label><?php endif; ?>
                    <input type="text" name="msgstr[<?= (int)$e['index'] ?>][]" value="<?= h($val) ?>" class="form-control form-control-sm mb-1">
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= $this->Form->button(__('admin.localization.save'), ['class' => 'btn btn-primary']) ?>
<?= $this->Form->end() ?>
