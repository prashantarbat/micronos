<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
/** @var list<array{id: string, number: string, heading: string, label: string, icon: string, markdown: string}> $sections */
$mdById = array_column($sections, 'markdown', 'id');
?>

<style>
/* ── Documentation: operator-manual layout ──────────────────────── */
.docx-hero {
  width: 46px; height: 46px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, var(--color-cyan), var(--color-accent-deep, var(--color-purple)));
  color: #fff; box-shadow: 0 6px 20px var(--color-cyan-dim, rgba(0, 0, 0, .25)); flex: 0 0 auto;
}
.docx-hero svg { width: 24px; height: 24px; }

.docx-switcher { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.docx-switcher a, .docx-switcher button {
  font-size: .75rem; font-weight: 600; padding: 7px 14px; border-radius: 8px;
  border: 1px solid var(--color-border); background: var(--color-surface-1);
  color: var(--text-secondary); text-decoration: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px; font-family: inherit;
  transition: all var(--transition-fast, 150ms);
}
.docx-switcher a:hover, .docx-switcher button:hover {
  color: var(--text-primary); border-color: var(--color-border-bright); background: var(--color-surface-2);
}
.docx-switcher a.active {
  background: var(--color-cyan-dim); border-color: var(--color-cyan); color: var(--color-cyan);
}
.docx-switcher svg { width: 14px; height: 14px; }

/* Fullpage Responsive Layout */
.docx-layout {
  display: grid;
  grid-template-columns: 310px minmax(0, 1fr);
  gap: 24px;
  align-items: start;
}
@media (max-width: 1080px) {
  .docx-layout { grid-template-columns: 260px minmax(0, 1fr); gap: 16px; }
}
@media (max-width: 900px) {
  .docx-layout { grid-template-columns: 1fr; }
  .docx-toc { position: static !important; height: auto !important; max-height: none !important; }
}

/* Fullpage Sticky Table of Contents Sidebar */
.docx-toc {
  position: sticky;
  top: calc(var(--topbar-height, 64px) + 16px);
  height: calc(100vh - var(--topbar-height, 64px) - 32px);
  max-height: calc(100vh - var(--topbar-height, 64px) - 32px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg, 14px);
  box-shadow: var(--shadow-sm);
  z-index: 10;
}
body.has-device-bar .docx-toc {
  top: calc(var(--device-bar-h, 32px) + var(--topbar-height, 64px) + 16px);
  height: calc(100vh - var(--device-bar-h, 32px) - var(--topbar-height, 64px) - 32px);
  max-height: calc(100vh - var(--device-bar-h, 32px) - var(--topbar-height, 64px) - 32px);
}
.docx-toc-head {
  padding: 14px 16px;
  background: var(--color-surface-2);
  border-bottom: 1px solid var(--color-border);
  flex: 0 0 auto;
}
.docx-toc-title {
  margin: 0 0 10px;
  font-size: .74rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--color-cyan);
  display: flex;
  align-items: center;
  gap: 8px;
}
.docx-toc-title svg { width: 15px; height: 15px; }
.docx-toc-filter {
  width: 100%;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright, var(--color-border));
  border-radius: 8px;
  padding: 8px 12px;
  font-size: .78rem;
  color: var(--text-primary);
  outline: none;
  font-family: inherit;
  transition: border-color var(--transition-fast, 150ms), box-shadow var(--transition-fast, 150ms);
}
.docx-toc-filter::placeholder { color: var(--text-muted); }
.docx-toc-filter:focus {
  border-color: var(--color-cyan);
  box-shadow: 0 0 0 3px var(--color-cyan-dim);
}
.docx-toc-list {
  flex: 1 1 0;
  min-height: 0;
  overflow-y: auto;
  padding: 10px 8px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  scrollbar-width: thin;
  scrollbar-color: var(--color-border-bright) transparent;
}
.docx-toc-list::-webkit-scrollbar { width: 5px; }
.docx-toc-list::-webkit-scrollbar-track { background: transparent; }
.docx-toc-list::-webkit-scrollbar-thumb { background: var(--color-border-bright); border-radius: 4px; }
.docx-toc-list::-webkit-scrollbar-thumb:hover { background: var(--color-cyan); }

.docx-toc-list a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: .8rem;
  line-height: 1.35;
  color: var(--text-secondary);
  text-decoration: none;
  transition: background var(--transition-fast, 150ms), color var(--transition-fast, 150ms);
}
.docx-toc-list a:hover {
  background: var(--color-surface-3);
  color: var(--text-primary);
}
.docx-toc-list a.active {
  background: var(--color-cyan-dim);
  color: var(--color-cyan);
  font-weight: 600;
}
.docx-toc-num {
  min-width: 22px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  font-size: .72rem;
  color: var(--text-muted);
}
.docx-toc-list a.active .docx-toc-num {
  color: var(--color-cyan);
}
.docx-toc-icon {
  width: 16px;
  height: 16px;
  display: flex;
  color: var(--color-cyan);
  opacity: .85;
  flex: 0 0 auto;
}
.docx-toc-icon svg { width: 16px; height: 16px; }
.docx-toc-empty { padding: 14px; font-size: .8rem; color: var(--text-muted); text-align: center; display: none; }
.docx-toc-foot {
  padding: 10px 14px;
  font-size: .68rem;
  color: var(--text-muted);
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-2);
  font-family: var(--font-mono);
  word-break: break-all;
  flex: 0 0 auto;
}

/* Section cards */
.docx-section {
  padding: 0;
  margin-bottom: 24px;
  overflow: hidden;
  scroll-margin-top: 84px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg, 14px);
  box-shadow: var(--shadow-sm);
}
.docx-section-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 20px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-2);
  flex-wrap: wrap;
}
.docx-section-title {
  margin: 0;
  font-size: 1.02rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 10px;
  line-height: 1.4;
}
.docx-section-title .ico {
  width: 18px;
  height: 18px;
  display: flex;
  color: var(--color-cyan);
  flex: 0 0 auto;
}
.docx-section-title .ico svg { width: 18px; height: 18px; }

/* Rendered markdown typography & content */
.docx-body {
  padding: 22px 24px 28px;
  font-size: .88rem;
  line-height: 1.75;
  color: var(--text-secondary);
}
.docx-body > :first-child { margin-top: 0; }
.docx-body > :last-child { margin-bottom: 0; }
.docx-body h3 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin: 1.8em 0 .6em; }
.docx-body h4 { font-size: .92rem; font-weight: 700; color: var(--text-primary); margin: 1.4em 0 .5em; }
.docx-body h5 { font-size: .84rem; font-weight: 700; color: var(--text-secondary); margin: 1.2em 0 .4em; text-transform: uppercase; letter-spacing: .05em; }
.docx-body p { margin: 0 0 .95em; }
.docx-body ul, .docx-body ol { margin: 0 0 1em; padding-left: 1.4em; }
.docx-body li { margin-bottom: .35em; }
.docx-body li > ul, .docx-body li > ol { margin-top: .3em; margin-bottom: 0; }
.docx-body a { color: var(--color-cyan); text-decoration: none; font-weight: 500; }
.docx-body a:hover { text-decoration: underline; }
.docx-body strong { color: var(--text-primary); font-weight: 700; }
.docx-body hr { border: none; border-top: 1px solid var(--color-border); margin: 1.6em 0; }
.docx-body blockquote {
  margin: 1.1em 0; padding: .8em 1.2em; border-left: 4px solid var(--color-cyan);
  background: var(--color-cyan-dim); border-radius: 0 8px 8px 0; color: var(--text-primary);
}

/* Code Blocks & Inline Code — Full Theme Preset Integration */
.docx-body code {
  font-family: var(--font-mono); font-size: .84em;
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  border-radius: 5px; padding: .15em .45em;
  color: var(--color-cyan);
  font-weight: 600;
}
.docx-body pre {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border-bright, var(--color-border));
  border-radius: 10px;
  padding: 16px 18px;
  overflow-x: auto;
  margin: 1.1em 0 1.35em;
  position: relative;
  box-shadow: var(--shadow-sm);
}
[data-mode="light"] .docx-body pre {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border-bright);
}
.docx-body pre code {
  background: transparent !important;
  border: none !important;
  padding: 0 !important;
  color: var(--text-primary) !important;
  font-size: .82rem;
  line-height: 1.65;
  font-family: var(--font-mono);
}
.docx-body .docx-raw { white-space: pre-wrap; color: var(--text-secondary); }

/* Theme-adaptive syntax highlighting tokens */
.docx-body pre code.hljs { background: transparent !important; padding: 0 !important; }
.hljs-keyword, .hljs-selector-tag, .hljs-subst, .hljs-doctag { color: var(--color-cyan) !important; font-weight: 600; }
.hljs-string, .hljs-title, .hljs-title.function_, .hljs-section, .hljs-attribute, .hljs-literal,
.hljs-template-tag, .hljs-template-variable, .hljs-type, .hljs-addition { color: var(--color-emerald) !important; }
.hljs-comment, .hljs-quote, .hljs-deletion, .hljs-meta { color: var(--text-muted) !important; font-style: italic; }
.hljs-number, .hljs-regexp, .hljs-link { color: var(--color-amber) !important; }
.hljs-variable, .hljs-params, .hljs-attr { color: var(--text-primary) !important; }
.hljs-built_in, .hljs-builtin-name, .hljs-symbol, .hljs-bullet { color: var(--color-purple, var(--color-accent-deep)) !important; font-weight: 600; }

/* Copy Code Button */
.docx-copy-btn {
  position: absolute;
  top: 8px; right: 8px;
  font-size: .72rem; font-weight: 600;
  padding: 5px 9px; border-radius: 6px;
  border: 1px solid var(--color-border);
  background: var(--color-surface-1);
  color: var(--text-secondary);
  cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  opacity: 0;
  transition: opacity var(--transition-fast, 150ms), background var(--transition-fast, 150ms), color var(--transition-fast, 150ms);
  font-family: inherit; box-shadow: var(--shadow-sm);
}
.docx-body pre:hover .docx-copy-btn { opacity: 1; }
.docx-copy-btn:hover { background: var(--color-surface-2); color: var(--text-primary); border-color: var(--color-border-bright); }

/* Markdown Tables */
.docx-body table {
  width: 100%; border-collapse: collapse; margin: 1.1em 0 1.5em; font-size: .84rem;
  border: 1px solid var(--color-border); border-radius: var(--radius-md, 10px);
  overflow: hidden; background: var(--color-surface-1); display: table;
}
.docx-body th, .docx-body td {
  padding: 10px 14px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: top;
}
.docx-body th {
  background: var(--color-surface-2); color: var(--text-primary); font-weight: 700; white-space: nowrap;
}
.docx-body tr:hover td { background: var(--color-surface-3); }
.docx-body tr:last-child td { border-bottom: none; }
.docx-body img { max-width: 100%; border-radius: 8px; }

@media print {
  .docx-toc, .docx-switcher, .page-header .btn { display: none !important; }
  .docx-layout { grid-template-columns: 1fr; }
  .docx-section { break-inside: avoid-page; border: 1px solid #ddd; }
  .docx-body { color: #111; }
}
</style>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div style="display:flex; align-items:center; gap:14px;">
      <span class="docx-hero">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
        </svg>
      </span>
      <div>
        <h1 class="page-title" style="margin:0;"><?= esc($docTitle) ?></h1>
        <p class="page-subtitle" style="margin:2px 0 0;"><?= esc($docSubtitle) ?></p>
      </div>
    </div>

    <div class="docx-switcher">
      <?php foreach ($guides as $guide): ?>
        <a href="<?= base_url('docs/' . $guide['slug']) ?>" class="<?= $guide['slug'] === $activeSlug ? 'active' : '' ?>">
          <?= esc($guide['label']) ?>
        </a>
      <?php endforeach; ?>
      <button type="button" onclick="window.print();">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Print
      </button>
    </div>
  </div>
</div>

<div class="docx-layout animate-in animate-in-delay-1">

  <!-- ============ TABLE OF CONTENTS (FULLPAGE SIDEBAR) ============ -->
  <aside class="docx-toc card card-glass">
    <div class="docx-toc-head">
      <div class="docx-toc-title">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
        </svg>
        Table of Contents
      </div>
      <input type="text" id="docxTocFilter" class="docx-toc-filter" placeholder="Filter sections (e.g. ldap, radius, proxy)…" autocomplete="off">
    </div>

    <nav class="docx-toc-list" id="docxTocList">
      <?php foreach ($sections as $section): ?>
        <a href="#<?= esc($section['id'], 'attr') ?>"
           data-anchor="<?= esc($section['id'], 'attr') ?>"
           data-label="<?= esc(strtolower($section['label']), 'attr') ?>">
          <span class="docx-toc-num"><?= esc($section['number']) ?>.</span>
          <span class="docx-toc-icon"><?= $section['icon'] ?></span>
          <span><?= esc($section['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="docx-toc-empty" id="docxTocEmpty">No section matches that filter.</div>

    <div class="docx-toc-foot"><?= esc($sourceFile) ?></div>
  </aside>

  <!-- ============ CONTENT ============ -->
  <div class="docx-sections">
    <?php foreach ($sections as $section): ?>
      <section class="docx-section card card-glass" id="<?= esc($section['id'], 'attr') ?>">
        <div class="docx-section-head">
          <span class="badge badge-info">Section <?= esc($section['number']) ?></span>
          <h2 class="docx-section-title">
            <span class="ico"><?= $section['icon'] ?></span>
            <?= esc($section['heading']) ?>
          </h2>
        </div>
        <div class="docx-body" data-id="<?= esc($section['id'], 'attr') ?>"></div>
      </section>
    <?php endforeach; ?>
  </div>
</div>

<?php
// Served from this appliance, never from a CDN: the guides have to render on a
// management network with no route to the internet. Both libraries ship under
// public/assets/vendor (see the README beside them for versions and licences).
// Loaded synchronously and in order, because the script below parses and
// highlights immediately.
?>
<script src="<?= base_url('assets/vendor/marked.min.js') ?>?v=<?= @filemtime(FCPATH . 'assets/vendor/marked.min.js') ?: '1' ?>"></script>
<script src="<?= base_url('assets/vendor/highlight.min.js') ?>?v=<?= @filemtime(FCPATH . 'assets/vendor/highlight.min.js') ?: '1' ?>"></script>
<script>
(function () {
  'use strict';

  // Markdown source per section, JSON-encoded (tags hex-escaped so it can
  // never terminate this script block).
  var MD = <?= json_encode($mdById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  // The guides link to each other by their standalone export name
  // (`MICRON_…_ARCHITECTURE.html`) or by their markdown source
  // (`MICRON_…_ARCHITECTURE.md`). Map those stems onto the in-app slugs so
  // cross-references resolve here instead of 404-ing.
  var DOC_BASE  = <?= json_encode(base_url('docs/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var DOC_LINKS = <?= json_encode($docLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  // GitHub-style slug, so in-document links keep resolving.
  function slugify(text) {
    return (text || '').toLowerCase().replace(/[^a-z0-9 -]/g, '').replace(/ /g, '-').replace(/^-+|-+$/g, '');
  }

  function parser() {
    if (window.marked && typeof window.marked.parse === 'function') { return window.marked.parse; }
    if (window.marked && window.marked.marked && typeof window.marked.marked.parse === 'function') { return window.marked.marked.parse; }
    return null;
  }

  function renderSections() {
    var parse = parser();

    document.querySelectorAll('.docx-body').forEach(function (el) {
      var md = MD[el.dataset.id] || '';

      if (!parse) {
        // marked unavailable (asset missing, or blocked by policy): show the
        // raw markdown rather than an empty section.
        var pre = document.createElement('pre');
        pre.className = 'docx-raw';
        pre.textContent = md;
        el.appendChild(pre);
        return;
      }

      var html;
      try {
        html = parse(md, { gfm: true, breaks: false });
      } catch (err) {
        var fallback = document.createElement('pre');
        fallback.className = 'docx-raw';
        fallback.textContent = md;
        el.appendChild(fallback);
        return;
      }

      el.innerHTML = html;

      // Point cross-guide links (either .html export or .md source) at the
      // in-app routes, keeping any fragment.
      el.querySelectorAll('a[href]').forEach(function (a) {
        var href = a.getAttribute('href') || '';
        var match = /^([A-Za-z0-9_]+)\.(?:html|md)(#.+)?$/.exec(href);
        if (match && Object.prototype.hasOwnProperty.call(DOC_LINKS, match[1])) {
          a.setAttribute('href', DOC_BASE + DOC_LINKS[match[1]] + (match[2] || ''));
        }
      });

      // Ids for the sub-headings, deduplicated across the whole page.
      var used = new Set();
      document.querySelectorAll('[id]').forEach(function (n) { used.add(n.id); });
      el.querySelectorAll('h3, h4, h5').forEach(function (h) {
        var base = slugify(h.textContent) || 'h', id = base, i = 2;
        while (used.has(id)) { id = base + '-' + (i++); }
        used.add(id);
        h.id = id;
      });

      // Syntax highlighting for code blocks
      el.querySelectorAll('pre code').forEach(function (code) {
        if (window.hljs) {
          try { hljs.highlightElement(code); } catch (e) {}
        }
      });

      // Add Copy Button to every code block
      el.querySelectorAll('pre').forEach(function (pre) {
        if (pre.querySelector('.docx-copy-btn')) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'docx-copy-btn';
        btn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Copy';
        btn.addEventListener('click', function () {
          var code = pre.querySelector('code');
          var text = code ? code.innerText : pre.innerText;
          navigator.clipboard.writeText(text).then(function () {
            btn.innerHTML = '✔ Copied!';
            setTimeout(function () {
              btn.innerHTML = '<svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg> Copy';
            }, 2000);
          });
        });
        pre.appendChild(btn);
      });
    });
  }

  /* ── TOC filter ─────────────────────────────────────────────── */
  function wireFilter() {
    var input = document.getElementById('docxTocFilter');
    var list  = document.getElementById('docxTocList');
    var empty = document.getElementById('docxTocEmpty');
    if (!input || !list) { return; }

    input.addEventListener('input', function () {
      var query = input.value.trim().toLowerCase();
      var visible = 0;
      list.querySelectorAll('a[data-anchor]').forEach(function (a) {
        var anchor = a.dataset.anchor || '';
        var label  = a.dataset.label || '';
        var mdText = (MD[anchor] || '').toLowerCase();
        var hit    = !query || label.indexOf(query) !== -1 || mdText.indexOf(query) !== -1;
        a.style.display = hit ? '' : 'none';
        if (hit) { visible++; }
      });
      if (empty) { empty.style.display = visible ? 'none' : 'block'; }
    });
  }

  /* ── Smooth scroll + scroll-spy ─────────────────────────────── */
  function wireNavigation() {
    var list  = document.getElementById('docxTocList');
    if (!list) { return; }
    var links = Array.prototype.slice.call(list.querySelectorAll('a[data-anchor]'));

    function setActive(id) {
      links.forEach(function (a) { a.classList.toggle('active', a.dataset.anchor === id); });
    }

    list.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-anchor]');
      if (!a) { return; }
      var target = document.getElementById(a.dataset.anchor);
      if (!target) { return; }
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', '#' + a.dataset.anchor);
      setActive(a.dataset.anchor);
    });

    // Scroll-spy: whichever section crosses the reading band owns the highlight.
    var sections = Array.prototype.slice.call(document.querySelectorAll('.docx-section'));

    function syncActive() {
      var bandTop    = 96;
      var bandBottom = window.innerHeight * 0.4;
      var current    = null;
      sections.forEach(function (s) {
        var rect = s.getBoundingClientRect();
        if (rect.top <= bandBottom && rect.bottom >= bandTop) { current = s.id; }
      });
      if (current) { setActive(current); }
    }

    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) { return; }
      ticking = true;
      window.requestAnimationFrame(function () { ticking = false; syncActive(); });
    }, { passive: true });
    window.addEventListener('resize', syncActive, { passive: true });

    // The first entry stays lit until a section reaches the reading band.
    if (links.length) { setActive(links[0].dataset.anchor); }
    syncActive();

    if (location.hash) {
      var initial = document.getElementById(decodeURIComponent(location.hash.slice(1)));
      if (initial) {
        setTimeout(function () { initial.scrollIntoView({ block: 'start' }); setActive(initial.id); }, 60);
      }
    }
  }

  renderSections();
  wireFilter();
  wireNavigation();
})();
</script>

<?= $this->endSection() ?>
