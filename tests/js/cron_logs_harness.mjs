#!/usr/bin/env node
/**
 * cron execution-history DOM harness — the page's log table, driven in Node.
 * ============================================================================
 * `app/Views/cron/index.php` draws the scheduler's execution history: it reads
 * `cron/logs` and renders one row per run — when it ran, its pid, the user it
 * ran as, the command, how long it took, whether it succeeded and a button that
 * opens the run's output. The PHP suite pins the endpoint and its answer; what
 * PHP cannot see is the row actually being built out of it.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a `document`
 * whose nodes really nest, and drives:
 *
 *   1. **The row.** The answer becomes one row per execution, with the stamp, pid
 *      and duration as the cells' text, the user and the status in the badges
 *      classed for them, and the command both as its cell's text and as that
 *      cell's `title` — the attribute set through the DOM, verbatim, which the
 *      harness pins with a command that would otherwise break out of it.
 *
 *   2. **The Inspect Output button.** It used to JSON-encode the run's output
 *      into an `onclick` attribute. It is now a node whose handler closes over its
 *      own run, which the harness pins by clicking it and reading the modal it
 *      opened — title and body, both as text.
 *
 *   3. **The escaping, structurally.** A run whose command, user or output looks
 *      like markup must render as those characters: the harness asserts the value
 *      is the node's text, that nothing shaped like an element became one, and
 *      that no node in the table was built from an HTML string.
 *
 * Run it directly:
 *
 *     node tests/js/cron_logs_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/cron/index.php';

// The page draws its rows with the shared builder, loaded from the layout. The
// harness loads the same shipped file into the sandbox for the same reason it
// reads the shipped view: the page cannot build a row without it, so driving the
// page with a stub would prove nothing about what the appliance serves.
const TABLE = 'public/assets/js/micronos-table.js';
const tableSource = fs.readFileSync(path.join(ROOT, TABLE), 'utf8');

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// The page's <script>, with the PHP echoes reduced to inert text so the body is
// valid JavaScript. The script is otherwise byte-for-byte the shipped one. The
// values are substituted bare because the view already quotes each echo
// (`MicronScheduler.fetch('<?= base_url('cron/logs') ?>')`).
const php = fs.readFileSync(path.join(ROOT, VIEW), 'utf8');
const scriptMatch = php.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error(`No <script> block was found in ${VIEW}.`);
  process.exit(1);
}
const script = scriptMatch[1]
  .replace(/<\?php[\s\S]*?\?>/g, '')
  .replace(/<\?=\s*base_url\(\s*'([^']*)'\s*\)\s*\?>/g, '$1')
  .replace(/<\?=\s*base_url\(\s*\)\s*\?>/g, '/')
  .replace(/<\?=\s*csrf_token\(\s*\)\s*\?>/g, 'csrf_token_name')
  .replace(/<\?=\s*csrf_hash\(\s*\)\s*\?>/g, 'rendered-hash')
  .replace(/<\?=[\s\S]*?\?>/g, 'x');

// ---------------------------------------------------------------------------
// A DOM just large enough for this page — and one whose nodes really nest, so a
// row's text can be read off the node the page built rather than off a string.
// ---------------------------------------------------------------------------
const TEXT_NODE = 3;

function makeTextNode(text) {
  return { nodeType: TEXT_NODE, textContent: String(text), children: [], childNodes: [] };
}

function makeEl(tag) {
  let text = '';
  const el = {
    nodeType: 1,
    tagName: String(tag).toUpperCase(),
    value: '',
    checked: false,
    action: '',
    // Only ever non-empty if a view assembles markup — which the row builders
    // must not, though the modal headings behind them still do.
    innerHTML: '',
    children: [],
    childNodes: [],
    attrs: {},
    style: {},
    classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    listeners: {},
    scrollTop: 0,
    scrollHeight: 0,
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    hasAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key); },
    appendChild(node) {
      this.childNodes.push(node);
      if (node.nodeType !== TEXT_NODE) this.children.push(node);
      return node;
    },
    // How many times this node's children were swapped rather than added to.
    replacements: 0,
    // What a re-render uses: the body is swapped in one go, never appended to.
    replaceChildren(...nodes) {
      this.childNodes = nodes;
      this.children = nodes.filter((node) => node.nodeType !== TEXT_NODE);
      this.replacements += 1;
    },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  // As in a browser, these are reflected attributes: setting the property is what
  // sets `title="…"`, and reading the attribute gives the same string back —
  // which is what makes `title` safe to fill with a command off the wire.
  for (const reflected of ['title', 'type', 'className']) {
    const attr = reflected === 'className' ? 'class' : reflected;
    Object.defineProperty(el, reflected, {
      get() { return Object.prototype.hasOwnProperty.call(this.attrs, attr) ? this.attrs[attr] : ''; },
      set(value) { this.attrs[attr] = String(value); },
    });
  }

  // As in a browser: reading textContent gives the node's text including its
  // descendants, and writing it replaces whatever children were there.
  const reader = {
    get() {
      if (this.childNodes.length) return this.childNodes.map((child) => child.textContent).join('');
      return text;
    },
    set(value) { text = String(value); this.childNodes = []; this.children = []; },
  };
  Object.defineProperty(el, 'textContent', reader);
  Object.defineProperty(el, 'innerText', reader);
  return el;
}

const byId = new Map();
const document = {
  getElementById(id) {
    if (!byId.has(id)) byId.set(id, makeEl('div'));
    return byId.get(id);
  },
  createElement(tag) { return makeEl(tag); },
  createElementNS(ns, tag) { return makeEl(tag); },
  createTextNode(text) { return makeTextNode(text); },
  querySelectorAll() { return []; },
  addEventListener() {},
};

// ---------------------------------------------------------------------------
// The daemon's answers, which the harness controls
// ---------------------------------------------------------------------------
const env = { entries: [], urls: [] };

function answer(url) {
  env.urls.push(String(url));
  return { entries: env.entries };
}

const sandbox = {
  document,
  console,
  __env: env,
  __check: check,
  MicronScheduler: {
    fetch: async (url) => ({ ok: true, json: async () => answer(url) }),
    poll: () => ({ stop() {} }),
    report: () => {},
  },
  setTimeout,
};
// A browser's `window` is the global object; the sandbox is this page's, so the
// shared scripts loaded into it find their global the same way.
sandbox.window = sandbox;
vm.createContext(sandbox);

// The layout loads the builder before any page script; the sandbox gets it in
// the same order.
vm.runInContext(tableSource, sandbox, { filename: TABLE });

// ---------------------------------------------------------------------------
// The driver, run in the page's own scope so it can reach the page's functions
// ---------------------------------------------------------------------------
const driver = `
;(async () => {
  const settle = () => new Promise((r) => setTimeout(r, 0));
  const body = document.getElementById('cronLogTableBody');
  const rows = () => body.children;
  const cells = (i) => (rows()[i] || { children: [] }).children;
  const texts = (i) => cells(i).map((td) => td.textContent);
  // Every row's nth cell, read defensively: a detail line that threw while
  // reporting a failure would hide the failure it was reporting.
  const column = (n) => rows().map((tr) => ((tr.children || [])[n] || {}).textContent);
  // Every node inside the body, so a value written as markup anywhere in the
  // table — a nested badge, not just a cell — is caught.
  const markupNodes = () => {
    const found = [];
    const walk = (node) => {
      if (node.innerHTML) found.push(node);
      (node.children || []).forEach(walk);
    };
    walk(body);
    return found;
  };
  const run = (over) => Object.assign({
    timestamp: '2026-10-08 02:00:03', pid: 4242, user: 'root',
    command: 'php spark backup:create', duration: '12.4s', status: 'SUCCESS',
    output: 'snapshot written to /var/backups/config.tar.gz',
  }, over);

  // 1. The row.
  __check('the table is drawn through the shared builder',
    typeof MicronTable === 'object' && typeof MicronTable.render === 'function', typeof MicronTable);

  const swaps = body.replacements;
  renderCronLogs([run({})]);
  __check('drawing the table swaps its body in one go', body.replacements === swaps + 1, String(body.replacements - swaps));
  __check('the answer becomes one row per execution', rows().length === 1, String(rows().length));
  __check('a row is as many cells as the header declares', cells(0).length === 7, String(cells(0).length));
  __check('the stamp, pid and duration are the cells text',
    texts(0)[0] === '2026-10-08 02:00:03' && texts(0)[1] === '4242' && texts(0)[4] === '12.4s',
    JSON.stringify(texts(0)));
  __check('the user is a badge holding the user as text',
    (cells(0)[2].children[0] || {}).className === 'badge badge-purple' && cells(0)[2].textContent === 'root',
    JSON.stringify(cells(0)[2].textContent));
  __check('the command is the cell text and its title, for the cell that truncates it',
    texts(0)[3] === 'php spark backup:create' && cells(0)[3].getAttribute('title') === 'php spark backup:create',
    JSON.stringify(cells(0)[3].getAttribute('title')));
  __check('the status is a badge classed for a success',
    (cells(0)[5].children[0] || {}).className === 'badge badge-success' && cells(0)[5].textContent === 'SUCCESS',
    JSON.stringify((cells(0)[5].children[0] || {}).className));

  renderCronLogs([run({ status: 'FAILED' })]);
  __check('a failure is the error badge', (cells(0)[5].children[0] || {}).className === 'badge badge-error',
    JSON.stringify((cells(0)[5].children[0] || {}).className));
  renderCronLogs([run({ duration: '' })]);
  __check('a missing duration falls back to 0.1s', texts(0)[4] === '0.1s', JSON.stringify(texts(0)[4]));

  // 2. The escaping, structurally: a value off the wire is a node's text.
  const markup = '<img src=x onerror=alert(1)>';
  renderCronLogs([run({ user: markup, command: markup, status: markup, output: markup })]);
  __check('a command that looks like an element is the cell text', texts(0)[3] === markup, JSON.stringify(texts(0)[3]));
  __check('a command that looks like an element is the title verbatim',
    cells(0)[3].getAttribute('title') === markup, JSON.stringify(cells(0)[3].getAttribute('title')));
  __check('an element-shaped user is the badge text with no element made from it',
    cells(0)[2].textContent === markup && cells(0)[2].children[0].children.length === 0,
    JSON.stringify(cells(0)[2].textContent));
  __check('the table is built from nodes, never from markup', markupNodes().length === 0,
    JSON.stringify(markupNodes().map((n) => String(n.tagName))));

  // 3. A re-render swaps the rows rather than adding to them.
  renderCronLogs([run({})]);
  renderCronLogs([run({}), run({ pid: 4243 })]);
  __check('a re-render swaps the rows rather than adding to them', rows().length === 2, String(rows().length));

  // 4. The Inspect Output button, clicked like an operator would click it.
  renderCronLogs([run({ pid: 9911, output: 'the run output body' })]);
  const inspectCell = cells(0)[6];
  const btn = inspectCell.children[0] || {};
  __check('the last cell holds the Inspect Output button as a node',
    inspectCell.children.length === 1 && String(btn.tagName).toLowerCase() === 'button'
      && btn.getAttribute('onclick') === null && btn.textContent === 'Inspect Output',
    JSON.stringify(String(btn.tagName)));

  btn.click();
  __check('clicking it opens the output modal titled for that execution',
    document.getElementById('outputModal').style.display === 'flex'
      && document.getElementById('outputModalTitle').textContent === 'Task PID 9911 Output',
    document.getElementById('outputModalTitle').textContent);
  __check('the modal body is the run output as text',
    document.getElementById('outputModalBody').innerText === 'the run output body',
    document.getElementById('outputModalBody').innerText);
  __check('the modal title is the icon node plus the title, never markup',
    document.getElementById('outputModalTitle').innerHTML === ''
      && document.getElementById('outputModalTitle').children.length === 1,
    String(document.getElementById('outputModalTitle').children.length));

  // A hostile output reaches the body as its text, not as anything else.
  renderCronLogs([run({ pid: 9912, output: markup })]);
  cells(0)[6].children[0].click();
  __check('a markup-shaped output reaches the modal body as text',
    document.getElementById('outputModalBody').innerText === markup
      && document.getElementById('outputModalBody').innerHTML === '',
    document.getElementById('outputModalBody').innerText);

  // 5. An absent list renders the one row that says so.
  let threw = null;
  try { renderCronLogs(undefined); } catch (err) { threw = err; }
  __check('a missing list renders the empty row instead of throwing',
    threw === null && rows().length === 1 && texts(0)[0] === 'No execution history recorded yet.',
    threw ? String(threw) : JSON.stringify(column(0)));
  __check('the empty row spans every column', rows()[0].children[0].getAttribute('colspan') === '7',
    String(rows()[0].children[0].getAttribute('colspan')));

  // 6. The fetch path.
  __env.entries = [run({ pid: 1 }), run({ pid: 2, status: 'FAILED' })];
  await fetchCronLogs();
  await settle();
  __check('the page reads the cron logs endpoint', __env.urls.indexOf('cron/logs') !== -1, JSON.stringify(__env.urls));
  __check('the fetched executions are rendered through the builder',
    rows().length === 2 && texts(1)[1] === '2', JSON.stringify(column(1)));

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 200 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The cron execution-history driver did not finish; the page script may have thrown.');
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
const failures = checks.filter((c) => !c.cond);
for (const c of checks) {
  console.log(`${c.cond ? 'PASS' : 'FAIL'} ${c.name}` + (c.detail && !c.cond ? `  [${c.detail}]` : ''));
}
console.log();

if (failures.length) {
  console.log(`FAILED: ${failures.length} check(s): ` + failures.map((f) => f.name).join(', '));
  process.exit(1);
}

console.log(`ALL CRON LOGS HARNESS CHECKS PASSED (${checks.length} checks)`);
