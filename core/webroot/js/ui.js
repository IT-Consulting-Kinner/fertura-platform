/**
 * Shared UI progressive enhancement (loaded by the admin AND module shells).
 * Loaded AFTER bootstrap.bundle.min.js, so the global `bootstrap` namespace is
 * available. Three responsibilities:
 *   1. Initialise Bootstrap tooltips (opt-in via data-bs-toggle="tooltip").
 *   2. Options refresh for UiKit reference fields: a [data-options-refresh]
 *      button re-fetches its select's options as JSON and rebuilds them in
 *      place (current selection kept), so a missing element can be created in
 *      another tab and picked up without leaving the form.
 *   3. A shared confirm modal that replaces native window.confirm() for
 *      destructive actions: any control with [data-confirm] opens the modal in
 *      core/templates/element/confirm_modal.php; confirming submits the
 *      control's form (or, for a link, follows its href).
 */
(function () {
  'use strict';

  // 1. Tooltips.
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });

  // 2. Reference-field options refresh. Endpoint contract (MODULE_UI.md): an
  //    app-relative module web route answering {options: [{value, label}]}.
  //    Options are rebuilt via createElement/textContent (never innerHTML), a
  //    leading empty "choose" option and the current selection survive; on any
  //    error the select is left untouched.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-options-refresh]');
    if (!btn) {
      return;
    }
    var url = btn.getAttribute('data-options-refresh') || '';
    var sel = document.querySelector(btn.getAttribute('data-options-target') || '');
    if (!sel || url.charAt(0) !== '/' || url.charAt(1) === '/') {
      return;
    }
    btn.disabled = true;
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
      .then(function (data) {
        var list = data && Array.isArray(data.options) ? data.options : [];
        var current = sel.value;
        var keepEmpty = sel.options.length && sel.options[0].value === '' ? sel.options[0] : null;
        while (sel.options.length) {
          sel.remove(0);
        }
        if (keepEmpty) {
          sel.add(keepEmpty);
        }
        list.forEach(function (o) {
          var opt = document.createElement('option');
          opt.value = String(o.value);
          opt.textContent = String(o.label);
          sel.add(opt);
        });
        if (current !== '') {
          sel.value = current; // keep the selection when it still exists
        }
      })
      .catch(function () { /* network/JSON error: leave the options unchanged */ })
      .finally(function () { btn.disabled = false; });
  });

  // 3. Confirm modal.
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
