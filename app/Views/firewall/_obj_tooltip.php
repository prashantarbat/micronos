<?php
/**
 * Shared hover-detail tooltip.
 *
 * Include once per firewall page (`<?= $this->include('firewall/_obj_tooltip') ?>`).
 * Any element carrying a `data-fw-tip` attribute (built by App\Libraries\ObjectTip)
 * shows its details in a floating card on hover or keyboard focus. Interface
 * <select>s marked `data-fw-iface` mirror the tip of their selected <option>, so
 * modals get the same hover details as the tables.
 */
?>
<style>
.fw-tip-title {
  font-weight: 700;
  font-size: 0.8rem;
  color: var(--text-primary);
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.fw-tip-badge {
  font-size: 0.58rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  padding: 1px 5px;
  border-radius: 4px;
  background: rgba(6, 182, 212, 0.15);
  border: 1px solid rgba(6, 182, 212, 0.35);
  color: #67e8f9;
}
.fw-tip-rows {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 3px 12px;
  margin: 0;
  align-items: baseline;
}
.fw-tip-rows dt {
  font-size: 0.66rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
  font-weight: 600;
  white-space: nowrap;
}
.fw-tip-rows dd {
  margin: 0;
  font-size: 0.74rem;
  color: var(--text-secondary);
  font-family: var(--font-mono);
  word-break: break-word;
}
#fw-tip-box {
  position: fixed;
  z-index: 3000;
  max-width: 360px;
  background: var(--color-surface-1, #111);
  border: 1px solid var(--color-border-bright, #333);
  border-radius: 8px;
  padding: 10px 12px;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.55);
  pointer-events: none;
  display: none;
}
#fw-tip-box.show { display: block; }
[data-fw-tip] { cursor: help; }
</style>
<script>
(function () {
  var box = document.createElement('div');
  box.id = 'fw-tip-box';
  document.body.appendChild(box);
  var active = null;

  function position(el) {
    var r = el.getBoundingClientRect();
    var bw = box.offsetWidth;
    var bh = box.offsetHeight;
    var x = r.left;
    var y = r.bottom + 8;
    if (x + bw > window.innerWidth - 12) { x = window.innerWidth - bw - 12; }
    if (x < 12) { x = 12; }
    if (y + bh > window.innerHeight - 12) { y = r.top - bh - 8; }
    if (y < 12) { y = 12; }
    box.style.left = x + 'px';
    box.style.top = y + 'px';
  }

  function show(el) {
    var html = el.getAttribute('data-fw-tip');
    if (!html) { return; }
    active = el;
    box.innerHTML = html;
    box.classList.add('show');
    position(el);
  }

  function hide() {
    active = null;
    box.classList.remove('show');
  }

  // Mirror-selects (interfaces, schedules, …) show the tooltip of the
  // currently selected <option>, so modals get the same details as tables.
  function isMirrorSelect(el) {
    return el && el.matches && el.matches('select[data-fw-iface], select[data-fw-select]');
  }

  function applyIfaceTip(sel) {
    var opt = sel.options && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
    var tip = opt ? opt.getAttribute('data-fw-tip') : null;
    if (tip) { sel.setAttribute('data-fw-tip', tip); }
    else { sel.removeAttribute('data-fw-tip'); }
  }

  document.addEventListener('mouseover', function (e) {
    var target = e.target;
    if (!target || !target.closest) { return; }
    var sel = target.closest('select[data-fw-iface], select[data-fw-select]');
    if (sel) { applyIfaceTip(sel); }
    var el = target.closest('[data-fw-tip]');
    if (el) {
      if (el !== active) { show(el); }
    } else if (active) {
      hide();
    }
  });

  document.addEventListener('mouseout', function (e) {
    if (!active) { return; }
    var target = e.target;
    if (target && target.closest && target.closest('[data-fw-tip]') === active) {
      var to = e.relatedTarget;
      if (!to || !active.contains(to)) { hide(); }
    }
  });

  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (isMirrorSelect(sel)) {
      applyIfaceTip(sel);
      if (active === sel) { show(sel); }
    }
  });

  document.addEventListener('focusin', function (e) {
    var target = e.target;
    if (!target || !target.closest) { return; }
    var sel = target.closest('select[data-fw-iface], select[data-fw-select]');
    if (sel) { applyIfaceTip(sel); }
    var el = target.closest('[data-fw-tip]');
    if (el) { show(el); }
  });

  document.addEventListener('focusout', hide);
  window.addEventListener('scroll', function () { if (active) { position(active); } }, true);
  window.addEventListener('resize', hide);
})();
</script>
