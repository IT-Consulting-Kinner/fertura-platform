<?php
/**
 * @var \App\View\AppView $this
 * @var array<int, array<string, mixed>> $entries
 * @var array<int, array<string, mixed>> $actions
 * @var array<int, array<string, mixed>> $entityTypes
 * @var string $action
 * @var string $entityType
 * @var string $moduleKey
 */
?>
<h1 class="h3 mb-3"><?= h(__('admin.audit.title')) ?></h1>

<?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end mb-3']) ?>
    <?php // Marker: this GET submit is an active state change -> the controller
          // persists the filters + page size as this user's list preference. ?>
    <?= $this->Form->hidden('_lp', ['value' => 1]) ?>
    <div class="col-auto"><label class="form-label small mb-0" for="audit-action"><?= h(__('admin.audit.label_action')) ?></label>
        <?php // Explicit text widget (not Form->control): control() would magically turn
              // the field into a <select> from the plural view var $actions, producing a
              // broken, unclearable dropdown. A free-text field with $actions as a
              // <datalist> keeps the ILIKE "contains" filter and stays clearable. ?>
        <?= $this->Form->text('action', ['id' => 'audit-action', 'value' => $action, 'class' => 'form-control form-control-sm', 'placeholder' => __('admin.audit.placeholder_contains'), 'list' => 'audit-action-options']) ?>
        <datalist id="audit-action-options"><?php foreach ($actions as $a): ?><option value="<?= h((string)$a['action']) ?>"></option><?php endforeach; ?></datalist></div>
    <div class="col-auto"><label class="form-label small mb-0"><?= h(__('admin.audit.label_entity_type')) ?></label>
        <?= $this->Form->select('entity_type', array_column($entityTypes, 'entity_type', 'entity_type'),
            ['value' => $entityType, 'empty' => __('admin.audit.option_all'), 'class' => 'form-select form-select-sm']) ?></div>
    <div class="col-auto"><label class="form-label small mb-0"><?= h(__('admin.audit.label_module')) ?></label>
        <?= $this->Form->control('module_key', ['label' => false, 'value' => $moduleKey, 'class' => 'form-control form-control-sm']) ?></div>
    <?= $this->UiKit->perPageSelect($perPage) ?>
    <div class="col-auto">
        <?= $this->Form->button(__('admin.audit.btn_filter'), ['class' => 'btn btn-primary btn-sm']) ?>
        <?php // Reset carries the marker with empty filters -> persists the cleared state. ?>
        <?= $this->Html->link(__('admin.audit.btn_reset'), ['action' => 'index', '?' => ['_lp' => 1]], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
        <?= $this->Html->link(
            __('admin.audit.btn_export'),
            ['action' => 'export', '?' => ['action' => $action, 'entity_type' => $entityType, 'module_key' => $moduleKey]],
            ['class' => 'btn btn-outline-secondary btn-sm'],
        ) ?>
    </div>
<?= $this->Form->end() ?>

<p class="text-muted small"><?= h(__('admin.audit.hint_last_100')) ?></p>
<table class="table table-sm table-hover align-middle">
    <thead><tr><th scope="col"><?= h(__('admin.audit.col_timestamp')) ?></th><th scope="col"><?= h(__('admin.audit.col_actor')) ?></th><th scope="col"><?= h(__('admin.audit.col_action')) ?></th><th scope="col"><?= h(__('admin.audit.col_entity')) ?></th><th scope="col"><?= h(__('admin.audit.col_module')) ?></th><th scope="col"><?= h(__('admin.audit.col_component')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td class="small text-nowrap"><?= h((string)$e['created_at']) ?></td>
            <td><?= $e['actor_username'] !== null ? h($e['actor_username']) : '<span class="text-muted small">' . h((string)$e['actor_user_id']) . '</span>' ?></td>
            <td><code class="small"><?= h($e['action']) ?></code></td>
            <td class="small"><?= h($e['entity_type']) ?><?php if (!empty($e['entity_label'])): ?>: <?= h($e['entity_label']) ?><?php elseif (!empty($e['entity_id'])): ?> <span class="text-muted"><?= h((string)$e['entity_id']) ?></span><?php endif; ?></td>
            <td class="small"><?= h($e['module_key']) ?: '–' ?></td>
            <td class="small"><?= h($e['component']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><tr><td colspan="6" class="text-muted"><?= h(__('admin.audit.empty')) ?></td></tr><?php endif; ?>
    </tbody>
</table>
<?= $this->UiKit->paginate($page, $perPage, $total, ['action' => 'index'], $query) ?>
