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

  // 1b. Tom Select: enhance every <select> into a searchable (and, for multi-
  //     selects, tag-style) control, system-wide. Opt out per field with
  //     [data-no-enhance]. Progressive enhancement: if the library is absent or a
  //     given select throws, the native <select> keeps working and still submits.
  if (typeof TomSelect !== 'undefined') {
    document.querySelectorAll('select:not([data-no-enhance])').forEach(function (sel) {
      if (sel.tomselect) {
        return; // already enhanced
      }
      try {
        new TomSelect(sel, {
          allowEmptyOption: true, // keep an empty "choose"/"all" option selectable
          maxOptions: null,       // never truncate our (bounded) option lists
          plugins: sel.multiple ? ['remove_button'] : []
        });
      } catch (e) {
        // One bad select must never break the rest of the page's enhancement.
      }
    });
  }

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
    // Same intent as the server-side App\Utility\AppUrl guard: only a safe
    // app-relative path may be fetched — a single leading slash, no
    // protocol-relative/backslash authority, no scheme. Uses a plain-ASCII
    // allowlist (rejecting backslash, spaces and control chars implicitly) so
    // the file stays free of control bytes. Defence in depth over CSP
    // connect-src 'self'.
    var unsafeChar = /[^A-Za-z0-9/\-._~%?=&#+,;@!$()*]/;
    if (!sel || url.charAt(0) !== '/' || url.charAt(1) === '/' ||
        unsafeChar.test(url) || url.indexOf('://') !== -1) {
      return;
    }
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.removeAttribute('data-refresh-failed');
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(String(r.status))); })
      .then(function (data) {
        var list = data && Array.isArray(data.options) ? data.options : [];
        // Preserve the selection across the rebuild: ALL selected values for a
        // multiple select, the single value otherwise.
        var current = sel.multiple
          ? Array.prototype.map.call(sel.selectedOptions, function (o) { return o.value; })
          : sel.value;
        // Preserve a leading empty "choose" option if the select had one.
        var keepEmpty = sel.options.length && sel.options[0].value === '' ? sel.options[0] : null;
        // Remove ALL children (options AND optgroup wrappers) so a select first
        // rendered from grouped options is not left with empty optgroup shells.
        while (sel.firstChild) {
          sel.removeChild(sel.firstChild);
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
        if (sel.multiple) {
          Array.prototype.forEach.call(sel.options, function (o) {
            o.selected = current.indexOf(o.value) !== -1;
          });
        } else {
          sel.value = current;
          // If the previously selected value is gone, fall back to the first option
          // (the empty "choose" entry when present) instead of an index of -1, which
          // renders blank AND omits the field from the form submission entirely.
          if (sel.value !== current) {
            sel.selectedIndex = sel.options.length ? 0 : -1;
          }
        }
        // If the select is Tom-Select-enhanced, re-read the rebuilt native options
        // and selection into the widget.
        if (sel.tomselect) {
          sel.tomselect.sync();
        }
      })
      .catch(function () {
        // Network/JSON/HTTP error: leave the options untouched but signal it, so
        // the user does not read "no change" as "refreshed, nothing new".
        btn.setAttribute('data-refresh-failed', 'true');
      })
      .finally(function () {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
      });
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
