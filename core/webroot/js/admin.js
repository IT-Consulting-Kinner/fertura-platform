/**
 * Admin-shell progressive enhancement. Loaded AFTER bootstrap.bundle.min.js, so
 * the global `bootstrap` namespace is available. Two responsibilities:
 *   1. Initialise Bootstrap tooltips (opt-in via data-bs-toggle="tooltip").
 *   2. A shared confirm modal that replaces native window.confirm() for
 *      destructive actions: any control with [data-confirm] opens the modal in
 *      core/templates/element/admin_confirm_modal.php; confirming submits the
 *      control's form (or, for a link, follows its href).
 */
(function () {
  'use strict';

  // 1. Tooltips.
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });

  // 2. Confirm modal.
  var modalEl = document.getElementById('confirmModal');
  if (!modalEl) {
    return;
  }
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var body = document.getElementById('confirmModalBody');
  var okBtn = document.getElementById('confirmModalOk');
  var pending = null;

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-confirm]');
    if (!trigger) {
      return;
    }
    e.preventDefault();
    pending = trigger;
    body.textContent = trigger.getAttribute('data-confirm') || '';
    okBtn.className = 'btn ' + (trigger.getAttribute('data-confirm-variant') || 'btn-danger');
    okBtn.textContent = trigger.getAttribute('data-confirm-ok') || okBtn.dataset.defaultLabel || 'OK';
    modal.show();
  });

  okBtn.addEventListener('click', function () {
    modal.hide();
    if (!pending) {
      return;
    }
    var form = pending.closest('form');
    if (form) {
      // requestSubmit keeps the activated submit button's name/value (if any);
      // fall back to submit() for older engines.
      form.requestSubmit ? form.requestSubmit(pending.tagName === 'BUTTON' ? pending : undefined) : form.submit();
    } else if (pending.tagName === 'A' && pending.href) {
      window.location.href = pending.href;
    }
    pending = null;
  });
})();
