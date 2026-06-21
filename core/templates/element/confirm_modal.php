<?php
/**
 * Shared confirm modal for destructive actions (rendered once by the admin AND
 * module layouts). A control with data-confirm="..." opens it (wiring in
 * webroot/js/ui.js) instead of native window.confirm(); confirming submits that
 * control's form.
 * Optional per-trigger attributes: data-confirm-ok (button label),
 * data-confirm-variant (e.g. "btn-danger" | "btn-warning").
 *
 * @var \App\View\AppView $this
 */
?>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmModalTitle"><?= h(__('admin.common.confirm_title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(__('admin.common.cancel')) ?>"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= h(__('admin.common.cancel')) ?></button>
                <button type="button" class="btn btn-danger" id="confirmModalOk" data-default-label="<?= h(__('admin.common.confirm')) ?>"><?= h(__('admin.common.confirm')) ?></button>
            </div>
        </div>
    </div>
</div>
