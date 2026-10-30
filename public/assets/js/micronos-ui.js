/*
 * MicronOS shared UI helpers.
 *
 *   MicronConfirm.ask({ title, text, confirmLabel, danger }) → Promise<boolean>
 *     An accessible <dialog> confirmation (focus is trapped by the browser,
 *     Escape cancels, focus returns to the trigger). Used instead of the
 *     native confirm() so destructive actions name the object they affect.
 *
 *   <form data-confirm="Delete tunnel “wg1”?" data-confirm-title="…" data-confirm-danger>
 *     Any form carrying data-confirm asks before it submits.
 *
 *   MicronToast.show(message, level)  level: success | error | warning | info
 *     A polite live-region notification for in-page (AJAX) results.
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  const MicronConfirm = {
    ask(opts) {
      opts = opts || {};
      return new Promise(resolve => {
        if (typeof HTMLDialogElement === 'undefined') { resolve(window.confirm(opts.text || 'Are you sure?')); return; }
        const opener = document.activeElement;
        const d = el('dialog', 'mc-dialog');
        d.setAttribute('aria-labelledby', 'mcDialogTitle');
        const body = el('div', 'mc-dialog__body');
        const h = el('h2', 'mc-dialog__title', opts.title || 'Please confirm');
        h.id = 'mcDialogTitle';
        body.append(h, el('p', 'mc-dialog__text', opts.text || 'Are you sure?'));
        const actions = el('div', 'mc-dialog__actions');
        const cancel = el('button', 'btn btn-secondary', opts.cancelLabel || 'Cancel');
        cancel.type = 'button';
        const ok = el('button', 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary'), opts.confirmLabel || 'Confirm');
        ok.type = 'button';
        actions.append(cancel, ok);
        d.append(body, actions);
        document.body.appendChild(d);
        const done = v => { d.close(); d.remove(); if (opener && opener.focus) opener.focus(); resolve(v); };
        cancel.addEventListener('click', () => done(false));
        ok.addEventListener('click', () => done(true));
        d.addEventListener('cancel', e => { e.preventDefault(); done(false); });
        d.showModal();
        (opts.danger ? cancel : ok).focus();
      });
    }
  };

  document.addEventListener('submit', e => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;
    e.preventDefault();
    MicronConfirm.ask({
      title: form.dataset.confirmTitle || 'Please confirm',
      text: form.getAttribute('data-confirm'),
      confirmLabel: form.dataset.confirmLabel || 'Confirm',
      danger: form.hasAttribute('data-confirm-danger'),
    }).then(yes => {
      if (!yes) return;
      form.dataset.confirmed = '1';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(e.submitter || undefined); else form.submit();
    });
  }, true);

  let region = null;
  const MicronToast = {
    show(message, level) {
      if (!region) {
        region = el('div', 'toast-region');
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
      }
      const t = el('div', 'alert alert-' + (level || 'info') + ' toast');
      t.append(el('span', 'alert-text', message));
      region.appendChild(t);
      setTimeout(() => t.remove(), level === 'error' ? 9000 : 5000);
    }
  };

  window.MicronConfirm = MicronConfirm;
  window.MicronToast = MicronToast;

  /*
   * Global modal policy:
   * Modals across the entire project must NEVER close when clicked outside (on the backdrop).
   * Modals can only be closed via explicit close triggers (e.g. Cancel buttons, 'X' buttons, or programmatic API).
   */
  function isModalBackdrop(node) {
    if (!node || node === document || node === document.body) return false;
    if (typeof node.matches === 'function') {
      if (node.matches(
        '.modal-backdrop, .mno-modal-backdrop, .rt-modal-backdrop, .iface-modal-backdrop, ' +
        '.plug-modal-backdrop, .wg-modal-backdrop, .ov-modal-backdrop, [class*="-modal-backdrop"], [class*="modal-backdrop"]'
      )) {
        return true;
      }
      if (node.classList && node.classList.contains('modal') && node.querySelector && (node.querySelector('.modal-dialog') || node.querySelector('.modal-content'))) {
        return true;
      }
    }
    return false;
  }

  // Intercept click and pointer events in the capturing phase so no view-level backdrop handler can close modals
  ['click', 'mousedown', 'pointerdown'].forEach(function (eventType) {
    document.addEventListener(eventType, function (e) {
      if (isModalBackdrop(e.target)) {
        e.stopImmediatePropagation();
        if (eventType === 'click') {
          e.preventDefault();
        }
      }
    }, true);
  });

  // For any Bootstrap modals: enforce static backdrop so Bootstrap's internal handler does not dismiss on backdrop click
  function enforceStaticModalBackdrops() {
    document.querySelectorAll('.modal').forEach(function (m) {
      m.setAttribute('data-bs-backdrop', 'static');
      m.setAttribute('data-backdrop', 'static');
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enforceStaticModalBackdrops);
  } else {
    enforceStaticModalBackdrops();
  }
})();
