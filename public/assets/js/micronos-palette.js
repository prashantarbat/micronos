/*
 * MicronOS command palette — Ctrl/⌘+K (or "/" outside a field) to jump to any page.
 *
 * The entries are read from the navigation already rendered on the page
 * (sidebar links, top-bar dropdown items), so the palette can never offer a
 * page the operator is not permitted to see and never drifts from the menus.
 * No network request, no data of its own.
 *
 * Accessible: role="dialog" + aria-modal, a combobox/listbox pair, arrow keys
 * move the selection, Enter opens, Escape closes and focus returns to where
 * it was.
 */
(function () {
  'use strict';

  function collect() {
    const seen = new Map();
    const add = (a, group) => {
      const href = a.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
      const label = (a.cloneNode(true));
      label.querySelectorAll('.nav-tag, .nav-badge-sm, svg').forEach(n => n.remove());
      const text = label.textContent.replace(/\s+/g, ' ').trim();
      if (!text) return;
      const key = href.split('#')[0];
      if (!seen.has(key)) seen.set(key, { href, text, group: group || '' });
    };
    document.querySelectorAll('.sidebar-nav a.nav-link').forEach(a => {
      const g = a.closest('.nav-group');
      add(a, g ? (g.querySelector('.nav-group-title') || {}).textContent : '');
    });
    document.querySelectorAll('.topnav-dropdown a.topnav-dropdown-item, a.topnav-group-btn').forEach(a => {
      const grp = a.closest('.topnav-group');
      const btn = grp ? grp.querySelector('.topnav-group-btn') : null;
      add(a, btn ? btn.textContent.replace(/\s+/g, ' ').trim() : '');
    });
    return Array.from(seen.values());
  }

  function score(entry, q) {
    if (!q) return 1;
    const hay = (entry.text + ' ' + entry.group).toLowerCase();
    let pos = 0;
    for (const ch of q) {
      pos = hay.indexOf(ch, pos);
      if (pos === -1) return 0;
      pos++;
    }
    const start = entry.text.toLowerCase().indexOf(q);
    return start === 0 ? 3 : start > 0 ? 2 : 1;
  }

  let root, input, list, entries = [], shown = [], active = 0, opener = null;

  function build() {
    root = document.createElement('div');
    root.className = 'cmdk';
    root.hidden = true;
    root.innerHTML =
      '<div class="cmdk-backdrop" data-close></div>' +
      '<div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Go to page">' +
      '  <div class="cmdk-search">' +
      '    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/></svg>' +
      '    <input type="text" class="cmdk-input" placeholder="Search pages and tools…" role="combobox" aria-expanded="true" aria-controls="cmdkList" aria-autocomplete="list" autocomplete="off" spellcheck="false">' +
      '    <kbd>Esc</kbd>' +
      '  </div>' +
      '  <ul class="cmdk-list" id="cmdkList" role="listbox" aria-label="Pages"></ul>' +
      '  <div class="cmdk-hint"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Ctrl</kbd><kbd>K</kbd> toggle</span></div>' +
      '</div>';
    document.body.appendChild(root);
    input = root.querySelector('.cmdk-input');
    list = root.querySelector('.cmdk-list');
    root.addEventListener('click', e => { if (e.target.hasAttribute('data-close')) close(); });
    input.addEventListener('input', render);
    input.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); go(shown[active]); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'Tab') { e.preventDefault(); }
    });
    list.addEventListener('click', e => {
      const li = e.target.closest('[data-idx]');
      if (li) go(shown[+li.dataset.idx]);
    });
  }

  function render() {
    const q = input.value.trim().toLowerCase();
    shown = entries.map(e => [score(e, q), e]).filter(x => x[0] > 0)
      .sort((a, b) => b[0] - a[0]).slice(0, 40).map(x => x[1]);
    active = 0;
    list.innerHTML = '';
    if (!shown.length) {
      const li = document.createElement('li');
      li.className = 'cmdk-empty';
      li.textContent = 'No page matches “' + input.value + '”.';
      list.appendChild(li);
      input.removeAttribute('aria-activedescendant');
      return;
    }
    shown.forEach((e, i) => {
      const li = document.createElement('li');
      li.id = 'cmdk-opt-' + i;
      li.setAttribute('role', 'option');
      li.dataset.idx = i;
      li.className = 'cmdk-item';
      const t = document.createElement('span'); t.className = 'cmdk-item-text'; t.textContent = e.text;
      const g = document.createElement('span'); g.className = 'cmdk-item-group'; g.textContent = e.group;
      li.append(t, g);
      list.appendChild(li);
    });
    highlight();
  }

  function highlight() {
    list.querySelectorAll('.cmdk-item').forEach((li, i) => li.setAttribute('aria-selected', i === active ? 'true' : 'false'));
    const cur = list.querySelector('#cmdk-opt-' + active);
    if (cur) { input.setAttribute('aria-activedescendant', cur.id); cur.scrollIntoView({ block: 'nearest' }); }
  }

  function move(d) {
    if (!shown.length) return;
    active = (active + d + shown.length) % shown.length;
    highlight();
  }

  function go(e) { if (e) window.location.href = e.href; }

  function open() {
    if (!root) build();
    entries = collect();
    opener = document.activeElement;
    root.hidden = false;
    input.value = '';
    render();
    input.focus();
  }

  function close() {
    if (!root || root.hidden) return;
    root.hidden = true;
    if (opener && opener.focus) opener.focus();
  }

  document.addEventListener('keydown', e => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target || {}).tagName || '') || (e.target && e.target.isContentEditable);
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      root && !root.hidden ? close() : open();
    } else if (e.key === '/' && !typing && !(root && !root.hidden)) {
      e.preventDefault();
      open();
    }
  });
  document.addEventListener('click', e => {
    if (e.target.closest('[data-open-palette]')) { e.preventDefault(); open(); }
  });

  window.MicronPalette = { open, close };
})();
