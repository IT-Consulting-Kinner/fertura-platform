<?php
/**
 * @var \App\View\AppView $this
 * @var list<array{key:string,type:string,version:string,domain:string,name:string}> $installed
 */
$options = [];
$dataMap = [];
foreach ($installed as $c) {
    $val = $c['key'] . '|' . $c['version'] . '|' . $c['type'] . '|' . $c['domain'];
    $options[$val] = $c['name'] . ' (' . $c['key'] . ' v' . $c['version'] . ')';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= h(__('admin.localization.import_title')) ?></h1>
    <?= $this->Html->link(__('admin.localization.back'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
</div>
<div class="alert alert-warning small"><?= h(__('admin.localization.import_unsigned_note')) ?></div>

<?= $this->Form->create(null, ['url' => ['action' => 'import'], 'type' => 'file']) ?>
<?= $this->Form->hidden('step', ['value' => 'preview']) ?>
<div class="row g-3" style="max-width:720px">
    <div class="col-12">
        <label class="form-label"><?= h(__('admin.localization.target_component')) ?></label>
        <select name="_target" id="targetSel" class="form-select" required>
            <option value=""><?= h(__('admin.localization.choose')) ?></option>
            <?php foreach ($options as $val => $label): ?>
                <option value="<?= h($val) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6">
        <label class="form-label"><?= h(__('admin.localization.locale')) ?></label>
        <input type="text" name="locale" class="form-control" placeholder="de_DE" pattern="[a-z]{2}_[A-Z]{2}" required>
    </div>
    <div class="col-6">
        <label class="form-label"><?= h(__('admin.localization.po_file')) ?></label>
        <input type="file" name="file" accept=".po" class="form-control" required>
    </div>
    <?= $this->Form->hidden('component', ['id' => 'fComponent']) ?>
    <?= $this->Form->hidden('version', ['id' => 'fVersion']) ?>
    <?= $this->Form->hidden('type', ['id' => 'fType']) ?>
    <?= $this->Form->hidden('domain', ['id' => 'fDomain']) ?>
    <div class="col-12">
        <?= $this->Form->button(__('admin.localization.preview'), ['class' => 'btn btn-primary']) ?>
    </div>
</div>
<?= $this->Form->end() ?>

<script>
document.getElementById('targetSel').addEventListener('change', function () {
    var p = this.value.split('|');
    document.getElementById('fComponent').value = p[0] || '';
    document.getElementById('fVersion').value = p[1] || '';
    document.getElementById('fType').value = p[2] || '';
    document.getElementById('fDomain').value = p[3] || '';
});
</script>
