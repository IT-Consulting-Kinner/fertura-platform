<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $settings
 */
?>
<h1 class="h3 mb-3">Konfiguration</h1>
<p class="text-muted small">Bekannte Core-Einstellungen. Greifen Vorgabewerte auch ohne DB-Eintrag. Secrets werden verschlüsselt gespeichert und nie im Klartext angezeigt.</p>

<div class="table-responsive">
<table class="table align-middle">
    <thead><tr><th>Schlüssel</th><th>Typ</th><th>Standard</th><th style="min-width:280px">Wert</th></tr></thead>
    <tbody>
    <?php foreach ($settings as $s): ?>
        <tr>
            <td>
                <code><?= h($s['namespace']) ?>.<?= h($s['key']) ?></code>
                <?php if ($s['secret']): ?><span class="badge text-bg-dark">secret</span><?php endif; ?>
            </td>
            <td><span class="text-muted small"><?= h($s['type']) ?><?php if ($s['min'] !== null): ?> [<?= (int)$s['min'] ?>–<?= (int)$s['max'] ?>]<?php endif; ?></span></td>
            <td class="small text-muted"><?= h(is_bool($s['default']) ? ($s['default'] ? 'true' : 'false') : (string)($s['default'] ?? '–')) ?></td>
            <td>
                <?= $this->Form->create(null, ['url' => ['action' => 'save'], 'class' => 'd-flex gap-2 align-items-center']) ?>
                <?= $this->Form->hidden('namespace', ['value' => $s['namespace']]) ?>
                <?= $this->Form->hidden('key', ['value' => $s['key']]) ?>
                <?php if ($s['type'] === 'bool'): ?>
                    <?= $this->Form->select('value', ['1' => 'true', '0' => 'false'],
                        ['value' => filter_var($s['value'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'class' => 'form-select form-select-sm']) ?>
                <?php else: ?>
                    <?= $this->Form->control('value', [
                        'label' => false,
                        'type' => $s['type'] === 'int' ? 'number' : 'text',
                        'value' => $s['secret'] ? '' : $s['value'],
                        'placeholder' => $s['secret'] ? ($s['value'] !== null ? '•••• gesetzt' : 'nicht gesetzt') : '',
                        'class' => 'form-control form-control-sm',
                    ]) ?>
                <?php endif; ?>
                <?= $this->Form->button('Speichern', ['class' => 'btn btn-outline-primary btn-sm']) ?>
                <?= $this->Form->end() ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
