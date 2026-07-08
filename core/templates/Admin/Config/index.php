<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $settings
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.config.title')) ?></h1>
<p class="text-muted small"><?= h(__('admin.config.intro')) ?></p>

<div class="table-responsive">
<table class="table table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.config.col_key')) ?></th><th scope="col"><?= h(__('admin.config.col_type')) ?></th><th scope="col"><?= h(__('admin.config.col_default')) ?></th><th scope="col" style="min-width:280px"><?= h(__('admin.config.col_value')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($settings as $s): ?>
        <tr>
            <td>
                <code><?= h($s['namespace']) ?>.<?= h($s['key']) ?></code>
                <?php if ($s['secret']): ?><span class="badge text-bg-dark">secret</span><?php endif; ?>
            </td>
            <td><span class="text-muted small"><?= h($s['type']) ?><?php if ($s['min'] !== null): ?> [<?= (int)$s['min'] ?>–<?= (int)$s['max'] ?>]<?php endif; ?></span></td>
            <td class="small text-muted"><?php
                $def = $s['default'];
                if (is_bool($def)) {
                    $def = $def ? 'true' : 'false';
                } elseif (is_array($def)) {
                    // json-typed settings carry array defaults — render them, don't cast.
                    $def = (string)json_encode($def, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $def = (string)($def ?? '–');
                }
                ?><?= h($def) ?></td>
            <td>
                <?= $this->Form->create(null, ['url' => ['action' => 'save'], 'class' => 'd-flex gap-2 align-items-center']) ?>
                <?= $this->Form->hidden('namespace', ['value' => $s['namespace']]) ?>
                <?= $this->Form->hidden('key', ['value' => $s['key']]) ?>
                <?php if ($s['type'] === 'bool'): ?>
                    <?= $this->Form->select('value', ['1' => 'true', '0' => 'false'],
                        ['value' => filter_var($s['value'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'class' => 'form-select form-select-sm',
                            'aria-label' => $s['namespace'] . '.' . $s['key']]) ?>
                <?php elseif (!empty($s['allowed'])): ?>
                    <?php $opts = [];
                    foreach ($s['allowed'] as $v) {
                        $opts[(string)$v] = (string)$v;
                    } ?>
                    <?= $this->Form->select('value', $opts,
                        ['value' => (string)$s['value'], 'class' => 'form-select form-select-sm',
                            'aria-label' => $s['namespace'] . '.' . $s['key']]) ?>
                <?php else: ?>
                    <?= $this->Form->control('value', [
                        'label' => false,
                        'type' => $s['type'] === 'int' ? 'number' : 'text',
                        'value' => $s['secret'] ? '' : $s['value'],
                        'placeholder' => $s['secret'] ? ($s['value'] !== null ? __('admin.config.secret_set') : __('admin.config.secret_unset')) : '',
                        'class' => 'form-control form-control-sm',
                        'aria-label' => $s['namespace'] . '.' . $s['key'],
                    ]) ?>
                <?php endif; ?>
                <?= $this->Form->button(__('admin.config.save'), ['class' => 'btn btn-outline-primary btn-sm']) ?>
                <?= $this->Form->end() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
