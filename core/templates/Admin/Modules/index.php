<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $modules
 * @var array<int, array<string, mixed>> $deps
 */
$badge = ['active' => 'success', 'installed_inactive' => 'secondary', 'installed' => 'secondary', 'error' => 'danger'];
?>
<h1 class="h3 mb-3">Module</h1>
<p class="text-muted small">Installation signierter Pakete erfolgt über die CLI (<code>bin/cake module install</code>). Hier steuern Sie den Lebenszyklus.</p>
<table class="table table-hover align-middle">
    <thead><tr><th>Schlüssel</th><th>Name</th><th>Version</th><th>Typ</th><th>Lizenz</th><th>Status</th><th class="text-end">Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($modules as $m): $active = $m['status'] === 'active'; ?>
        <tr>
            <td><code><?= h($m['module_key']) ?></code></td>
            <td><?= h($m['name']) ?></td>
            <td><?= h($m['version']) ?></td>
            <td><?= h($m['type']) ?></td>
            <td><?= filter_var($m['requires_license'], FILTER_VALIDATE_BOOLEAN) ? '<span class="badge text-bg-info">erforderlich</span>' : '–' ?></td>
            <td><span class="badge text-bg-<?= $badge[$m['status']] ?? 'secondary' ?>"><?= h($m['status']) ?></span></td>
            <td class="text-end">
                <div class="d-inline-flex gap-1">
                <?php if ($active): ?>
                    <?= $this->Form->postLink('Deaktivieren', ['action' => 'deactivate', $m['module_key']], ['class' => 'btn btn-warning btn-sm']) ?>
                <?php else: ?>
                    <?= $this->Form->postLink('Aktivieren', ['action' => 'activate', $m['module_key']], ['class' => 'btn btn-success btn-sm']) ?>
                    <?= $this->Form->postLink('Entfernen', ['action' => 'delete', $m['module_key']], ['class' => 'btn btn-outline-danger btn-sm', 'confirm' => 'Modul ' . $m['module_key'] . ' samt Schema entfernen?']) ?>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($modules === []): ?><tr><td colspan="7" class="text-muted">Keine Module installiert.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2 class="h5 mt-4">Abhängigkeiten</h2>
<?php if ($deps === []): ?>
    <p class="text-muted">Keine Abhängigkeiten erfasst.</p>
<?php else: ?>
    <ul class="list-group">
    <?php foreach ($deps as $d): ?>
        <li class="list-group-item">
            <code><?= h($d['module']) ?></code> &rarr; <code><?= h($d['requires']) ?></code>
            <?php if (!empty($d['required_version'])): ?><span class="text-muted small">(<?= h($d['required_version']) ?>)</span><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
